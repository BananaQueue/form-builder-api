<?php

namespace App\Http\Controllers;

use App\Support\FormCodeMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Throwable;

class LegacyLookupController extends Controller
{
    public function categories(): JsonResponse
    {
        try {
            $categories = DB::table('categories')
                ->select('id', 'name')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'categories' => $categories,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Failed to retrieve categories'], 500);
        }
    }

    public function forms(Request $request): JsonResponse
    {
        $currentUserId = (int) $request->session()->get('user_id');
        $isSuperAdmin = $request->session()->get('role') === 'super_admin';
        $targetUserId = $currentUserId;

        if ($isSuperAdmin && $request->query('user_id') !== null && is_numeric($request->query('user_id'))) {
            $targetUserId = (int) $request->query('user_id');
        }

        try {
            $forms = DB::select(<<<'SQL'
                SELECT
                    f.id,
                    f.form_code,
                    f.title,
                    f.description,
                    f.created_at,
                    f.category_id,
                    c.name as category_name,
                    COUNT(DISTINCT CASE
                        WHEN q.question_type != 'section' THEN q.id
                    END) as question_count,
                    COUNT(DISTINCT CASE WHEN a.id IS NOT NULL THEN r.id END) as response_count
                FROM forms f
                LEFT JOIN categories c ON f.category_id = c.id
                LEFT JOIN questions q ON f.id = q.form_id
                LEFT JOIN responses r ON f.id = r.form_id
                LEFT JOIN answers a ON a.response_id = r.id
                WHERE f.created_by = ?
                GROUP BY
                    f.id,
                    f.form_code,
                    f.title,
                    f.description,
                    f.created_at,
                    f.category_id,
                    c.name
                ORDER BY f.created_at DESC
                SQL, [$targetUserId]);

            return response()->json([
                'success' => true,
                'forms' => $forms,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Failed to retrieve forms'], 500);
        }
    }

    public function formDetails(Request $request): JsonResponse
    {
        $formId = (int) $request->query('id', 0);
        if ($formId <= 0) {
            return response()->json(['error' => 'Form ID is required'], 400);
        }

        try {
            $forms = DB::select(<<<'SQL'
                SELECT
                    f.id,
                    f.form_code,
                    f.privacy_notice,
                    f.step_mode,
                    f.title,
                    f.description,
                    f.category_id,
                    c.name as category_name,
                    f.created_by,
                    COUNT(DISTINCT CASE
                        WHEN q.question_type != 'section' THEN q.id
                    END) as question_count,
                    f.created_at
                FROM forms f
                LEFT JOIN categories c ON f.category_id = c.id
                LEFT JOIN questions q ON f.id = q.form_id
                WHERE f.id = ?
                GROUP BY
                    f.id,
                    f.form_code,
                    f.privacy_notice,
                    f.step_mode,
                    f.title,
                    f.description,
                    f.category_id,
                    c.name,
                    f.created_by,
                    f.created_at
                SQL, [$formId]);

            $form = $forms[0] ?? null;
            if (! $form) {
                return response()->json(['error' => 'Form not found'], 404);
            }

            $currentUserId = (int) $request->session()->get('user_id');
            $isSuperAdmin = $request->session()->get('role') === 'super_admin';
            $form = $this->rowToArray($form);

            if (! $isSuperAdmin && (int) ($form['created_by'] ?? 0) !== $currentUserId) {
                return response()->json(['error' => 'You can only view your own forms'], 403);
            }

            unset($form['created_by']);

            $questions = DB::select(<<<'SQL'
                SELECT
                    id,
                    question_text,
                    question_type,
                    description,
                    rating_scale,
                    number_min,
                    number_max,
                    number_step,
                    datetime_type,
                    position,
                    is_required,
                    condition_question_id,
                    condition_type,
                    condition_value
                FROM questions
                WHERE form_id = ? AND is_active = 1
                ORDER BY position ASC
                SQL, [$formId]);

            $form['questions'] = array_map(function (object|array $question): array {
                $question = $this->rowToArray($question);
                $options = DB::select(<<<'SQL'
                    SELECT option_text, position
                    FROM question_options
                    WHERE question_id = ?
                    ORDER BY position ASC
                    SQL, [$question['id']]);

                $question['options'] = array_map(
                    fn (object|array $option): mixed => $this->rowToArray($option)['option_text'],
                    $options,
                );

                return $question;
            }, $questions);

            return response()->json([
                'success' => true,
                'form' => $form,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Failed to retrieve form details'], 500);
        }
    }


    public function publicFormByCode(Request $request): JsonResponse
    {
        $formCode = trim((string) $request->query('code', ''));
        if ($formCode === '') {
            return response()->json(['error' => 'Form code is required'], 400);
        }

        $uniqueCodeCandidate = $formCode;
        if (str_contains($formCode, '-')) {
            $parts = explode('-', $formCode);
            $uniqueCodeCandidate = (string) end($parts);
        }

        try {
            $form = $this->findPublicFormByCode($formCode, $uniqueCodeCandidate);
            if (! $form) {
                return response()->json(['error' => 'Form not found'], 404);
            }

            $form = $this->rowToArray($form);
            unset($form['form_code']); // internal to matching (see findPublicFormByCode); never part of this public response
            $questions = DB::select('SELECT id, question_text, question_type, description, rating_scale, number_min, number_max, number_step, datetime_type, position, is_required, condition_question_id, condition_type, condition_value FROM questions WHERE form_id = ? AND is_active = 1 ORDER BY position ASC', [$form['id']]);

            $form['questions'] = array_map(function (object|array $question): array {
                $question = $this->rowToArray($question);
                $options = DB::select('SELECT option_text, position FROM question_options WHERE question_id = ? ORDER BY position ASC', [$question['id']]);
                $question['options'] = array_map(fn (object|array $option): mixed => $this->rowToArray($option)['option_text'], $options);

                return $question;
            }, $questions);

            return response()->json(['success' => true, 'form' => $form]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Failed to retrieve form'], 500);
        }
    }

    public function responses(Request $request): JsonResponse
    {
        $formId = (int) $request->query('form_id', 0);
        if ($formId <= 0) {
            return response()->json(['error' => 'Form ID is required'], 400);
        }

        $currentUserId = (int) $request->session()->get('user_id');
        $isSuperAdmin = $request->session()->get('role') === 'super_admin';

        try {
            $form = DB::table('forms')
                ->select('id', 'title', 'created_by')
                ->where('id', $formId)
                ->first();

            if (! $form) {
                return response()->json(['error' => 'Form not found'], 404);
            }

            if (! $isSuperAdmin && (int) $form->created_by !== $currentUserId) {
                return response()->json(['error' => 'You do not have permission to view responses for this form'], 403);
            }

            $responses = DB::select(<<<'SQL'
                SELECT
                    r.id,
                    r.submitted_at,
                    COUNT(a.id) as answer_count
                FROM responses r
                LEFT JOIN answers a ON a.response_id = r.id
                WHERE r.form_id = ?
                GROUP BY r.id, r.submitted_at
                ORDER BY r.submitted_at DESC
                SQL, [$formId]);

            $responses = array_map(fn (object|array $response): array => $this->rowToArray($response), $responses);

            return response()->json([
                'success' => true,
                'form' => [
                    'id' => $form->id,
                    'title' => $form->title,
                ],
                'responses' => $responses,
                'total_responses' => count($responses),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Failed to retrieve responses'], 500);
        }
    }

    public function responseDetails(Request $request): JsonResponse
    {
        $responseId = (int) $request->query('id', 0);
        if ($responseId <= 0) {
            return response()->json(['error' => 'Response ID is required'], 400);
        }

        $currentUserId = (int) $request->session()->get('user_id');
        $isSuperAdmin = $request->session()->get('role') === 'super_admin';

        try {
            $responses = DB::select(<<<'SQL'
                SELECT
                    r.id,
                    r.form_id,
                    r.submitted_at,
                    f.title as form_title,
                    f.created_by as form_owner_id
                FROM responses r
                JOIN forms f ON r.form_id = f.id
                WHERE r.id = ?
                SQL, [$responseId]);

            $response = $responses[0] ?? null;
            if (! $response) {
                return response()->json(['error' => 'Response not found'], 404);
            }

            $response = $this->rowToArray($response);
            if (! $isSuperAdmin && (int) $response['form_owner_id'] !== $currentUserId) {
                return response()->json(['error' => 'You do not have permission to view this response'], 403);
            }

            unset($response['form_owner_id']);

            $answers = DB::select(<<<'SQL'
                SELECT
                    a.id,
                    a.question_id,
                    a.answer_text,
                    q.question_text,
                    q.question_type,
                    q.description
                FROM answers a
                JOIN questions q ON a.question_id = q.id
                WHERE a.response_id = ?
                ORDER BY q.position ASC
                SQL, [$responseId]);

            $response['answers'] = array_map(fn (object|array $answer): array => $this->rowToArray($answer), $answers);

            return response()->json([
                'success' => true,
                'response' => $response,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Failed to retrieve response details'], 500);
        }
    }
    public function exportResponses(Request $request): JsonResponse|Response
    {
        $formId = (int) $request->query('form_id', 0);
        if ($formId <= 0) {
            return response()->json(['error' => 'Form ID is required'], 400);
        }

        $currentUserId = (int) $request->session()->get('user_id');
        $isSuperAdmin = $request->session()->get('role') === 'super_admin';

        try {
            $form = DB::table('forms')
                ->select('id', 'title', 'created_by')
                ->where('id', $formId)
                ->first();

            if (! $form) {
                return response()->json(['error' => 'Form not found'], 404);
            }

            if (! $isSuperAdmin && (int) $form->created_by !== $currentUserId) {
                return response()->json(['error' => 'You do not have permission to export responses for this form'], 403);
            }

            $this->auditResponseExport($request, $form);

            $questions = DB::select(<<<'SQL'
                SELECT id, question_text, position
                FROM questions
                WHERE form_id = ?
                ORDER BY position ASC
                SQL, [$formId]);
            $questions = array_map(fn (object|array $question): array => $this->rowToArray($question), $questions);

            $responses = DB::select(<<<'SQL'
                SELECT id, submitted_at
                FROM responses
                WHERE form_id = ?
                ORDER BY submitted_at DESC
                SQL, [$formId]);
            $responses = array_map(fn (object|array $response): array => $this->rowToArray($response), $responses);

            $answersByResponseAndQuestion = [];
            if (count($responses) > 0 && count($questions) > 0) {
                $responseIds = array_map(fn (array $response): mixed => $response['id'], $responses);
                $questionIds = array_map(fn (array $question): mixed => $question['id'], $questions);
                $responsePlaceholders = implode(',', array_fill(0, count($responseIds), '?'));
                $questionPlaceholders = implode(',', array_fill(0, count($questionIds), '?'));

                $answers = DB::select(<<<SQL
                    SELECT response_id, question_id, answer_text
                    FROM answers
                    WHERE response_id IN ({$responsePlaceholders})
                      AND question_id IN ({$questionPlaceholders})
                    SQL, array_merge($responseIds, $questionIds));

                foreach ($answers as $answer) {
                    $answer = $this->rowToArray($answer);
                    $answersByResponseAndQuestion[(int) $answer['response_id']][(int) $answer['question_id']] = $answer['answer_text'];
                }
            }

            $handle = fopen('php://temp', 'r+');
            fputcsv($handle, $this->neutralizeCsvRow(array_merge(['Submitted At'], array_map(fn (array $question): mixed => $question['question_text'], $questions))));

            foreach ($responses as $response) {
                $row = [$response['submitted_at']];
                foreach ($questions as $question) {
                    $row[] = $answersByResponseAndQuestion[(int) $response['id']][(int) $question['id']] ?? '';
                }
                fputcsv($handle, $this->neutralizeCsvRow($row));
            }

            rewind($handle);
            $csv = stream_get_contents($handle);
            fclose($handle);

            $safeTitle = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $form->title);
            $safeTitle = trim((string) $safeTitle, '_') ?: 'form';
            $filename = $safeTitle.'_responses_'.now()->format('Y-m-d').'.csv';

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Failed to export responses'], 500);
        }
    }
    private function findPublicFormByCode(string $formCode, string $uniqueCodeCandidate): object|array|null
    {
        $fields = 'f.id, f.form_code, f.title, f.description, f.privacy_notice, f.step_mode, f.category_id, c.name as category_name, f.created_at';
        $sql = "SELECT {$fields} FROM forms f LEFT JOIN categories c ON f.category_id = c.id WHERE f.form_code = ?";
        $forms = DB::select($sql, [$formCode]);
        if ($forms) {
            return $forms[0];
        }

        // The frontend rebuilds share URLs from a form's CURRENT,
        // untruncated title every time one is generated, but the stored
        // form_code's slug is truncated at creation (see
        // LegacyFormWriteController::generateFormCodeWithSlug) - so a
        // freshly-built link's slug almost never exactly matches the
        // stored one, even for the form it was just generated for. RIGHT()/
        // LENGTH() is an exact trailing-substring comparison, not a LIKE
        // pattern, so there's no wildcard for attacker input to exploit -
        // narrows candidates only; FormCodeMatcher (shared with the submit
        // endpoint) makes the actual match decision below.
        if ($uniqueCodeCandidate !== '' && $uniqueCodeCandidate !== $formCode) {
            $suffixSql = "SELECT {$fields} FROM forms f LEFT JOIN categories c ON f.category_id = c.id WHERE RIGHT(f.form_code, LENGTH(?)) = ?";
            $candidates = DB::select($suffixSql, [$uniqueCodeCandidate, $uniqueCodeCandidate]);
            foreach ($candidates as $candidate) {
                if (FormCodeMatcher::matches($formCode, $candidate->form_code)) {
                    return $candidate;
                }
            }
        }

        return null;
    }
    private function auditResponseExport(Request $request, object $form): void
    {
        try {
            DB::table('audit_logs')->insert([
                'actor_user_id' => $request->session()->get('user_id'),
                'actor_username' => $request->session()->get('username'),
                'actor_role' => $request->session()->get('role'),
                'action' => 'RESPONSES_EXPORTED',
                'entity_type' => 'form',
                'entity_id' => $form->id,
                'entity_label' => $form->title,
                'metadata' => json_encode(['owner_user_id' => isset($form->created_by) ? (int) $form->created_by : null], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
    private function rowToArray(object|array $row): array
    {
        return is_array($row) ? $row : get_object_vars($row);
    }

    /**
     * Neutralize CSV formula injection. Answer text comes from untrusted public
     * submitters; a value like "=HYPERLINK(...)" or "+cmd|..." would execute as a
     * formula when the exported file is opened in Excel/Sheets. Prefixing any cell
     * that begins with a formula trigger with a single quote forces the spreadsheet
     * to treat it as literal text. See OWASP "CSV Injection".
     */
    private function neutralizeCsvRow(array $row): array
    {
        return array_map(fn ($cell): string => $this->neutralizeCsvCell($cell), $row);
    }

    private function neutralizeCsvCell(mixed $cell): string
    {
        $value = (string) ($cell ?? '');
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}