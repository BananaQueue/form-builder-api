<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class LegacyFormWriteController extends Controller
{
    public function saveForm(Request $request): JsonResponse
    {
        if ($request->session()->get('logged_in') !== true) {
            return response()->json(['error' => 'Authentication required'], 401);
        }

        $data = $request->json()->all();
        if (! is_array($data) || ! isset($data['title'], $data['questions'])) {
            return response()->json(['error' => 'Invalid data provided'], 400);
        }

        try {
            $formCode = null;
            $formId = DB::transaction(function () use ($data, $request, &$formCode): string {
                $formCode = $this->generateFormCodeWithSlug((string) $data['title']);
                DB::insert('INSERT INTO forms (title, description, category_id, form_code, created_by, privacy_notice, step_mode) VALUES (?, ?, ?, ?, ?, ?, ?)', [$data['title'], $data['description'] ?? "\u{00A0}", $data['category_id'] ?? 1, $formCode, (int) $request->session()->get('user_id'), 1, $data['step_mode'] ?? 0]);
                $formId = (string) DB::getPdo()->lastInsertId();
                $questions = is_array($data['questions']) ? $data['questions'] : [];
                $questionIdMap = [];

                foreach ($questions as $index => $question) {
                    DB::insert('INSERT INTO questions (form_id, question_text, question_type, description, rating_scale, number_min, number_max, number_step, datetime_type, position, is_required, condition_question_id, condition_type, condition_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$formId, $question['question_text'] ?? ($question['text'] ?? ''), $question['question_type'] ?? ($question['type'] ?? 'text'), $question['description'] ?? null, $question['rating_scale'] ?? null, $question['number_min'] ?? null, $question['number_max'] ?? null, $question['number_step'] ?? null, $question['datetime_type'] ?? null, $index, $question['is_required'] ?? 1, null, $question['condition_type'] ?? 'equals', null]);
                    $dbQuestionId = (string) DB::getPdo()->lastInsertId();
                    $clientTempId = (string) ($question['id'] ?? $index);
                    $questionIdMap[$clientTempId] = $dbQuestionId;

                    if (isset($question['options']) && is_array($question['options'])) {
                        foreach ($question['options'] as $optIndex => $option) {
                            DB::insert('INSERT INTO question_options (question_id, option_text, position) VALUES (?, ?, ?)', [$dbQuestionId, $option, $optIndex]);
                        }
                    }
                }

                foreach ($questions as $index => $question) {
                    $condRef = $question['condition_question_id'] ?? null;
                    if ($condRef === null || $condRef === '') {
                        continue;
                    }
                    $dbQuestionId = $questionIdMap[(string) ($question['id'] ?? $index)] ?? null;
                    $conditionDbId = $questionIdMap[(string) $condRef] ?? null;
                    if ($dbQuestionId && $conditionDbId) {
                        DB::update('UPDATE questions SET condition_question_id = ?, condition_type = ?, condition_value = ? WHERE id = ?', [$conditionDbId, $question['condition_type'] ?? 'equals', $question['condition_value'] ?? null, $dbQuestionId]);
                    }
                }

                return $formId;
            });

            $this->auditFormCreated($request, $formId, (string) $data['title']);
            return response()->json(['success' => true, 'message' => 'Form saved successfully', 'form_id' => $formId, 'form_code' => $formCode]);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['error' => 'Failed to save form'], 500);
        }
    }


    public function updateForm(Request $request): JsonResponse
    {
        if ($request->session()->get('logged_in') !== true) {
            return response()->json(['error' => 'Authentication required'], 401);
        }

        $data = $request->json()->all();
        if (! is_array($data) || ! isset($data['form_id'], $data['title'], $data['questions'])) {
            return response()->json(['error' => 'Invalid data provided'], 400);
        }

        $formId = (int) $data['form_id'];
        $currentUserId = (int) $request->session()->get('user_id');
        $isSuperAdmin = $request->session()->get('role') === 'super_admin';
        $ownerRow = null;
        $auditChanges = [];

        try {
            DB::transaction(function () use ($data, $formId, $currentUserId, $isSuperAdmin, &$ownerRow, &$auditChanges): void {
                $owners = DB::select('SELECT f.id, f.title, f.description, f.category_id, f.step_mode, f.created_by, u.username AS owner_username FROM forms f LEFT JOIN users u ON u.id = f.created_by WHERE f.id = ? FOR UPDATE', [$formId]);
                $ownerRow = $owners[0] ?? null;
                if (! $ownerRow) {
                    throw new \RuntimeException('FORM_NOT_FOUND');
                }
                $owner = $this->rowToArray($ownerRow);
                if (! $isSuperAdmin && (int) ($owner['created_by'] ?? 0) !== $currentUserId) {
                    throw new \RuntimeException('FORM_FORBIDDEN');
                }

                $questions = is_array($data['questions']) ? $data['questions'] : [];
                $existingQuestionRows = array_map(
                    fn ($row) => $this->rowToArray($row),
                    DB::select('SELECT id, question_text, question_type, description, is_required FROM questions WHERE form_id = ?', [$formId])
                );
                foreach ($existingQuestionRows as &$existingQuestionRow) {
                    $options = DB::select('SELECT option_text FROM question_options WHERE question_id = ? ORDER BY position ASC', [(int) $existingQuestionRow['id']]);
                    $existingQuestionRow['options'] = array_map(fn ($option) => $this->rowToArray($option)['option_text'], $options);
                }
                unset($existingQuestionRow);
                $existingIds = array_map(fn ($row) => (int) $row['id'], $existingQuestionRows);
                $auditChanges = array_merge(
                    $this->describeFormAuditChanges($owner, $data),
                    $this->describeQuestionAuditChanges($existingQuestionRows, $questions)
                );

                DB::update('UPDATE forms SET title = ?, description = ?, category_id = ?, privacy_notice = ?, step_mode = ? WHERE id = ?', [$data['title'], $data['description'] ?? "\u{00A0}", $data['category_id'] ?? 1, 1, $data['step_mode'] ?? 0, $formId]);
                $questionIdMap = [];
                $keptIds = [];

                foreach ($questions as $index => $question) {
                    $clientId = $question['id'] ?? null;
                    $isExisting = $this->isExistingQuestionId($clientId, $existingIds);
                    $values = [$question['question_text'] ?? ($question['text'] ?? ''), $question['question_type'] ?? ($question['type'] ?? 'text'), $question['description'] ?? null, $question['rating_scale'] ?? null, $question['number_min'] ?? null, $question['number_max'] ?? null, $question['number_step'] ?? null, $question['datetime_type'] ?? null, $question['position'] ?? $index, $question['is_required'] ?? 1, 1, null, $question['condition_type'] ?? 'equals', null];

                    if ($isExisting) {
                        $dbQuestionId = (int) $clientId;
                        DB::update('UPDATE questions SET question_text = ?, question_type = ?, description = ?, rating_scale = ?, number_min = ?, number_max = ?, number_step = ?, datetime_type = ?, position = ?, is_required = ?, is_active = ?, condition_question_id = ?, condition_type = ?, condition_value = ? WHERE id = ? AND form_id = ?', array_merge($values, [$dbQuestionId, $formId]));
                    } else {
                        DB::insert('INSERT INTO questions (form_id, question_text, question_type, description, rating_scale, number_min, number_max, number_step, datetime_type, position, is_required, is_active, condition_question_id, condition_type, condition_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_merge([$formId], $values));
                        $dbQuestionId = (int) DB::getPdo()->lastInsertId();
                    }

                    $questionIdMap[(string) ($clientId ?? $index)] = $dbQuestionId;
                    $keptIds[] = $dbQuestionId;
                    DB::delete('DELETE FROM question_options WHERE question_id = ?', [$dbQuestionId]);
                    if (isset($question['options']) && is_array($question['options'])) {
                        foreach ($question['options'] as $optIndex => $option) {
                            DB::insert('INSERT INTO question_options (question_id, option_text, position) VALUES (?, ?, ?)', [$dbQuestionId, $option, $optIndex]);
                        }
                    }
                }

                foreach ($existingIds as $existingId) {
                    if (in_array($existingId, $keptIds, true)) continue;
                    $answerCountRows = DB::select('SELECT COUNT(*) AS total FROM answers WHERE question_id = ?', [$existingId]);
                    $answerCount = (int) ($this->rowToArray($answerCountRows[0] ?? ['total' => 0])['total'] ?? 0);
                    if ($answerCount > 0) {
                        DB::update('UPDATE questions SET is_active = 0 WHERE id = ? AND form_id = ?', [$existingId, $formId]);
                    } else {
                        DB::delete('DELETE FROM questions WHERE id = ? AND form_id = ?', [$existingId, $formId]);
                    }
                }

                foreach ($questions as $index => $question) {
                    $condRef = $question['condition_question_id'] ?? null;
                    if ($condRef === null || $condRef === '') continue;
                    $dbQuestionId = $questionIdMap[(string) ($question['id'] ?? $index)] ?? null;
                    $conditionDbId = $questionIdMap[(string) $condRef] ?? null;
                    if ($dbQuestionId && $conditionDbId) {
                        DB::update('UPDATE questions SET condition_question_id = ?, condition_type = ?, condition_value = ? WHERE id = ?', [$conditionDbId, $question['condition_type'] ?? 'equals', $question['condition_value'] ?? null, $dbQuestionId]);
                    }
                }
            });

            $this->auditFormUpdated($request, $formId, (string) $data['title'], $auditChanges);
            $this->notifyOwnerIfAdminEdited($request, $ownerRow, $formId, (string) $data['title']);
            return response()->json(['success' => true, 'message' => 'Form updated successfully', 'form_id' => $formId]);
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'FORM_NOT_FOUND') return response()->json(['error' => 'Form not found'], 404);
            if ($exception->getMessage() === 'FORM_FORBIDDEN') return response()->json(['error' => 'You can only edit your own forms'], 403);
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['error' => 'Failed to update form'], 500);
        }
    }

    public function deleteForm(Request $request): JsonResponse
    {
        if ($request->session()->get('logged_in') !== true) {
            return response()->json(['error' => 'Authentication required'], 401);
        }

        $data = $request->json()->all();
        if (! is_array($data) || ! isset($data['form_id'])) {
            return response()->json(['error' => 'Form ID is required'], 400);
        }

        $formId = (int) $data['form_id'];
        $deletionReason = trim((string) ($data['deletion_reason'] ?? ''));
        $currentUserId = (int) $request->session()->get('user_id');
        $isSuperAdmin = $request->session()->get('role') === 'super_admin';
        $form = null;

        try {
            DB::transaction(function () use ($request, $formId, $deletionReason, $currentUserId, $isSuperAdmin, &$form): void {
                $forms = DB::select('SELECT id, title, created_by FROM forms WHERE id = ?', [$formId]);
                $form = $forms[0] ?? null;
                if (! $form) {
                    throw new \RuntimeException('FORM_NOT_FOUND');
                }
                $form = $this->rowToArray($form);
                $formOwnerId = (int) ($form['created_by'] ?? 0);
                $isOtherUsersForm = $formOwnerId > 0 && $formOwnerId !== $currentUserId;

                if (! $isSuperAdmin && $isOtherUsersForm) {
                    throw new \RuntimeException('FORM_FORBIDDEN_DELETE');
                }
                if ($isSuperAdmin && $deletionReason === '') {
                    throw new \RuntimeException('FORM_DELETE_REASON_REQUIRED');
                }

                DB::delete('DELETE FROM forms WHERE id = ?', [$formId]);
                $this->auditFormDeleted($request, $form, $deletionReason, $isSuperAdmin);
                if ($isSuperAdmin && $isOtherUsersForm) {
                    $this->notifyOwnerFormDeleted($request, $form, $deletionReason);
                }
            });

            return response()->json(['success' => true, 'message' => 'Form deleted successfully']);
        } catch (\RuntimeException $exception) {
            return match ($exception->getMessage()) {
                'FORM_NOT_FOUND' => response()->json(['error' => 'Form not found'], 404),
                'FORM_FORBIDDEN_DELETE' => response()->json(['error' => 'You can only delete your own forms'], 403),
                'FORM_DELETE_REASON_REQUIRED' => response()->json(['error' => 'Deletion reason is required'], 400),
                default => throw $exception,
            };
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['error' => 'Failed to delete form'], 500);
        }
    }
    private function generateFormCodeWithSlug(string $title, int $codeLength = 7): string
    {
        $uniqueCode = $this->generateUniqueFormCode($codeLength);
        $slug = $this->generateSlugFromTitle($title);
        $maxSlugLength = max(1, 20 - strlen($uniqueCode) - 1);

        if (strlen($slug) > $maxSlugLength) {
            $slug = trim(substr($slug, 0, $maxSlugLength), '-');
        }

        return ($slug === '' ? 'form' : $slug).'-'.$uniqueCode;
    }

    private function generateSlugFromTitle(string $title): string
    {
        $slug = strtolower($title);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $slug) ?: '', '-');
        if (strlen($slug) > 50) {
            $slug = substr($slug, 0, 50);
            $lastHyphen = strrpos($slug, '-');
            if ($lastHyphen !== false) $slug = substr($slug, 0, $lastHyphen);
        }
        return $slug === '' ? 'form' : $slug;
    }

    private function generateUniqueFormCode(int $length = 7): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';
            for ($i = 0; $i < $length; $i++) $code .= $characters[random_int(0, strlen($characters) - 1)];
            if (! DB::select('SELECT id FROM forms WHERE form_code = ?', [$code])) return $code;
        }
        return $this->generateUniqueFormCode($length + 2);
    }

    private function auditFormCreated(Request $request, string $formId, string $title): void
    {
        try {
            DB::table('audit_logs')->insert(['actor_user_id' => $request->session()->get('user_id'), 'actor_username' => $request->session()->get('username'), 'actor_role' => $request->session()->get('role'), 'action' => 'FORM_CREATED', 'entity_type' => 'form', 'entity_id' => (int) $formId, 'entity_label' => $title, 'metadata' => json_encode(['changes' => ['New form']]), 'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255), 'created_at' => now()]);
        } catch (Throwable $exception) {
        }
    }
    private function isExistingQuestionId(mixed $clientId, array $existingQuestionIds): bool
    {
        return $clientId !== null && $clientId !== '' && is_numeric($clientId) && in_array((int) $clientId, $existingQuestionIds, true);
    }

    private function rowToArray(object|array $row): array
    {
        return is_array($row) ? $row : get_object_vars($row);
    }

    private function describeFormAuditChanges(array $before, array $data): array
    {
        $changes = [];

        if ($this->normalizeAuditText($before['title'] ?? '') !== $this->normalizeAuditText($data['title'] ?? '')) {
            $changes[] = 'Edited form title';
        }

        if ($this->normalizeAuditText($before['description'] ?? '') !== $this->normalizeAuditText($data['description'] ?? "\u{00A0}")) {
            $changes[] = 'Edited form description';
        }

        if ((int) ($before['category_id'] ?? 1) !== (int) ($data['category_id'] ?? 1)) {
            $changes[] = 'Changed form category';
        }

        $beforeStepMode = (int) ($before['step_mode'] ?? 0);
        $afterStepMode = (int) ($data['step_mode'] ?? 0);
        if ($beforeStepMode !== $afterStepMode) {
            $changes[] = $afterStepMode === 1 ? 'Enabled step mode' : 'Disabled step mode';
        }

        return $changes;
    }

    private function describeQuestionAuditChanges(array $beforeQuestions, array $afterQuestions): array
    {
        $beforeById = [];
        foreach ($beforeQuestions as $question) {
            $beforeById[(string) $question['id']] = $question;
        }

        $afterExistingIds = [];
        $changes = [];

        foreach ($afterQuestions as $question) {
            $id = $question['id'] ?? null;
            $type = $question['question_type'] ?? ($question['type'] ?? '');
            $isSection = $type === 'section';
            $isExisting = $id !== null && isset($beforeById[(string) $id]);

            if (! $isExisting) {
                $changes[] = $isSection ? 'Added section' : 'Added question';
                continue;
            }

            $afterExistingIds[(string) $id] = true;
            $before = $beforeById[(string) $id];
            $beforeType = $before['question_type'] ?? '';
            $beforeIsSection = $beforeType === 'section';
            $titleChanged = $this->normalizeAuditText($before['question_text'] ?? '') !== $this->normalizeAuditText($question['question_text'] ?? ($question['text'] ?? ''));
            $descriptionChanged = $this->normalizeAuditText($before['description'] ?? '') !== $this->normalizeAuditText($question['description'] ?? '');

            if ($beforeType !== $type) {
                $changes[] = $beforeIsSection ? 'Changed section type' : 'Changed question type';
            }

            if (! $beforeIsSection && (int) ($before['is_required'] ?? 1) !== (int) ($question['is_required'] ?? 1)) {
                $changes[] = ((int) ($question['is_required'] ?? 1) === 1) ? 'Marked question required' : 'Marked question optional';
            }

            if ($titleChanged || ($beforeIsSection && $descriptionChanged)) {
                $changes[] = $beforeIsSection ? 'Edited section' : 'Edited question text';
            }

            if (! $beforeIsSection) {
                $beforeOptions = $this->normalizeQuestionOptions($before['options'] ?? []);
                $afterOptions = $this->normalizeQuestionOptions($question['options'] ?? []);
                if ($beforeOptions !== $afterOptions) {
                    $addedOptions = array_values(array_diff($afterOptions, $beforeOptions));
                    $deletedOptions = array_values(array_diff($beforeOptions, $afterOptions));

                    if (count($addedOptions) > 0 && count($deletedOptions) === 0) {
                        $changes[] = 'Added options';
                    } elseif (count($deletedOptions) > 0 && count($addedOptions) === 0) {
                        $changes[] = 'Deleted options';
                    } else {
                        $changes[] = 'Edited options';
                    }
                }
            }
        }

        foreach ($beforeById as $id => $question) {
            if (isset($afterExistingIds[$id])) {
                continue;
            }
            $changes[] = (($question['question_type'] ?? '') === 'section') ? 'Deleted section' : 'Deleted question';
        }

        return array_values(array_unique($changes));
    }

    private function normalizeQuestionOptions(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        return array_values(array_map(fn ($option) => $this->normalizeAuditText($option), $options));
    }

    private function normalizeAuditText(mixed $value): string
    {
        return trim(str_replace("\u{00A0}", ' ', (string) ($value ?? '')));
    }

    private function auditFormUpdated(Request $request, int $formId, string $title, array $changes = []): void
    {
        try { DB::table('audit_logs')->insert(['actor_user_id' => $request->session()->get('user_id'), 'actor_username' => $request->session()->get('username'), 'actor_role' => $request->session()->get('role'), 'action' => 'FORM_UPDATED', 'entity_type' => 'form', 'entity_id' => $formId, 'entity_label' => $title, 'metadata' => json_encode(['changes' => count($changes) > 0 ? array_values(array_unique($changes)) : ['Updated form details']]), 'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255), 'created_at' => now()]); } catch (Throwable $exception) {}
    }

    private function notifyOwnerIfAdminEdited(Request $request, object|array|null $ownerRow, int $formId, string $title): void
    {
        try {
            if ($request->session()->get('role') !== 'super_admin' || ! $ownerRow) return;
            $owner = $this->rowToArray($ownerRow);
            $recipientId = (int) ($owner['created_by'] ?? 0);
            if ($recipientId <= 0 || $recipientId === (int) $request->session()->get('user_id')) return;
            DB::table('notifications')->insert(['recipient_user_id' => $recipientId, 'type' => 'FORM_EDITED', 'form_id' => $formId, 'form_title' => $title, 'message' => "Your form '{$title}' was reviewed and edited by a Super Administrator.", 'admin_id' => $request->session()->get('user_id'), 'admin_name' => $request->session()->get('username'), 'created_at' => now()]);
        } catch (Throwable $exception) {}
    }
    private function auditFormDeleted(Request $request, array $form, string $deletionReason, bool $isSuperAdmin): void
    {
        try { DB::table('audit_logs')->insert(['actor_user_id' => $request->session()->get('user_id'), 'actor_username' => $request->session()->get('username'), 'actor_role' => $request->session()->get('role'), 'action' => 'FORM_DELETED', 'entity_type' => 'form', 'entity_id' => (int) $form['id'], 'entity_label' => $form['title'], 'metadata' => json_encode(['owner_user_id' => (int) ($form['created_by'] ?? 0), 'deletion_reason' => $deletionReason, 'super_admin_action' => $isSuperAdmin]), 'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 255), 'created_at' => now()]); } catch (Throwable $exception) {}
    }

    private function notifyOwnerFormDeleted(Request $request, array $form, string $deletionReason): void
    {
        try { DB::table('notifications')->insert(['recipient_user_id' => (int) ($form['created_by'] ?? 0), 'type' => 'FORM_DELETED', 'form_id' => (int) $form['id'], 'form_title' => $form['title'], 'message' => "Your form '{$form['title']}' was removed by a Super Administrator.", 'deletion_reason' => $deletionReason, 'admin_id' => $request->session()->get('user_id'), 'admin_name' => $request->session()->get('username'), 'created_at' => now()]); } catch (Throwable $exception) {}
    }
}