<?php

namespace App\Http\Controllers;

use App\Support\FormCodeMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class LegacySubmissionController extends Controller
{
    public function submitResponse(Request $request): JsonResponse
    {
        $data = $request->json()->all();
        if (! is_array($data) || ! isset($data['form_id'], $data['answers'], $data['form_code']) || ! is_array($data['answers'])) {
            return response()->json(['error' => 'Invalid data provided'], 400);
        }

        $formId = (int) $data['form_id'];
        $formCode = trim((string) $data['form_code']);
        $answers = $data['answers'];
        $rateLimitKey = $formId.'|'.($request->ip() ?: 'unknown');

        if ($this->isRateLimited($rateLimitKey)) {
            return response()->json(['error' => 'Too many submissions. Please try again later.'], 429);
        }

        try {
            // The share code, not the numeric id, is the actual capability
            // that makes a form "public" - reading a form already requires
            // it (LegacyLookupController::publicFormByCode). Submitting used
            // to accept only the id, which is a small sequential integer:
            // anyone could walk id=1,2,3... and inject responses into every
            // form in the system, including ones whose link was never
            // shared. See FormCodeMatcher for why an exact match isn't
            // required - shared with the read endpoint so both agree on
            // what a valid code is.
            $formRow = DB::selectOne('SELECT form_code FROM forms WHERE id = ?', [$formId]);
            if (! $formRow || ! FormCodeMatcher::matches($formCode, $formRow->form_code)) {
                return response()->json(['error' => 'Form not found'], 404);
            }

            $this->recordRateLimitAttempt($rateLimitKey);
            $questions = DB::select('SELECT id, question_text, question_type, number_min, number_max, is_required, condition_question_id, condition_type, condition_value FROM questions WHERE form_id = ? AND is_active = 1 ORDER BY position ASC', [$formId]);

            $questionsById = [];
            foreach ($questions as $question) {
                $question = $this->rowToArray($question);
                $questionsById[(int) $question['id']] = $question;
            }

            $answersByQuestionId = [];
            foreach ($answers as $answer) {
                $questionId = (int) ($answer['question_id'] ?? 0);
                if ($questionId <= 0 || ! isset($questionsById[$questionId])) {
                    return response()->json(['error' => 'Invalid data provided'], 400);
                }
                $answersByQuestionId[$questionId] = $this->normalizeAnswerText($answer['answer_text'] ?? '');
            }

            $optionsByQuestionId = $this->loadOptionsByQuestionId(array_keys($questionsById));
            foreach ($questionsById as $questionId => $question) {
                $answerText = $answersByQuestionId[$questionId] ?? '';
                $visible = $this->isQuestionVisible($question, $answersByQuestionId, $questionsById);
                $required = $question['is_required'] === 1 || $question['is_required'] === '1' || $question['is_required'] === true;

                if ($visible && $question['question_type'] !== 'section' && $required && trim($answerText) === '') {
                    return response()->json(['error' => 'Invalid data provided'], 400);
                }

                $validationError = $visible ? $this->validateAnswer($question, $answerText, $optionsByQuestionId[$questionId] ?? []) : null;
                if ($validationError) {
                    return response()->json(['error' => $validationError], 400);
                }
            }

            $responseId = DB::transaction(function () use ($formId, $questionsById, $answersByQuestionId): string {
                DB::insert('INSERT INTO responses (form_id) VALUES (?)', [$formId]);
                $responseId = (string) DB::getPdo()->lastInsertId();

                foreach ($questionsById as $questionId => $question) {
                    DB::insert('INSERT INTO answers (response_id, question_id, question_text, question_type, answer_text) VALUES (?, ?, ?, ?, ?)', [$responseId, $questionId, $question['question_text'], $question['question_type'], $answersByQuestionId[$questionId] ?? '']);
                }

                return $responseId;
            });

            return response()->json(['success' => true, 'message' => 'Response submitted successfully', 'response_id' => $responseId]);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['error' => 'Failed to submit response'], 500);
        }
    }

    private function normalizeAnswerText(mixed $value): string
    {
        if (is_array($value)) {
            $value = implode(',', array_map('strval', $value));
        }
        return substr((string) ($value ?? ''), 0, 20000);
    }

    private function splitAnswerOptions(string $value): array
    {
        return trim($value) === '' ? [] : array_map('trim', explode(',', $value));
    }

    private function isQuestionVisible(array $question, array $answersByQuestionId, array $questionsById): bool
    {
        $conditionQuestionId = $question['condition_question_id'] ?? null;
        if (! $conditionQuestionId) {
            return true;
        }
        $conditionType = $question['condition_type'] ?: 'equals';
        $conditionAnswer = trim($answersByQuestionId[(int) $conditionQuestionId] ?? '');
        if ($conditionType === 'is_answered') {
            return $conditionAnswer !== '';
        }
        if ($conditionAnswer === '') {
            return false;
        }
        $conditionValue = trim((string) ($question['condition_value'] ?? ''));
        $parentQuestion = $questionsById[(int) $conditionQuestionId] ?? null;
        if (($parentQuestion['question_type'] ?? '') === 'checkbox') {
            $selected = $this->splitAnswerOptions($conditionAnswer);
            if (in_array($conditionType, ['contains', 'option_selected', 'equals'], true)) {
                return in_array($conditionValue, $selected, true);
            }
            if (in_array($conditionType, ['not_contains', 'not_equals'], true)) {
                return ! in_array($conditionValue, $selected, true);
            }
        }
        return $conditionType === 'not_equals' ? $conditionAnswer !== $conditionValue : $conditionAnswer === $conditionValue;
    }

    private function validateAnswer(array $question, string $answerText, array $options): ?string
    {
        $type = $question['question_type'];
        $trimmed = trim($answerText);
        if ($trimmed === '' || $type === 'section') {
            return null;
        }
        if ($type === 'email' && ! filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            return 'Invalid data provided';
        }
        if ($type === 'number') {
            if (! is_numeric($trimmed)) {
                return 'Invalid data provided';
            }
            $number = (float) $trimmed;
            if ($question['number_min'] !== null && $number < (float) $question['number_min']) {
                return 'Invalid data provided';
            }
            if ($question['number_max'] !== null && $number > (float) $question['number_max']) {
                return 'Invalid data provided';
            }
            return null;
        }
        if ($type === 'datetime') {
            $datePart = '[0-9]{4}-[0-9]{2}-[0-9]{2}';
            $timePart = '[0-9]{2}:[0-9]{2}';
            $oneValue = '('.$datePart.'|'.$timePart.'|'.$datePart.'T'.$timePart.')';
            return preg_match('/^'.$oneValue.'( to '.$oneValue.')?$/', $trimmed) ? null : 'Invalid data provided';
        }
        if (in_array($type, ['multiple_choice', 'rating'], true) && ! in_array($trimmed, $options, true)) {
            return 'Invalid data provided';
        }
        if ($type === 'checkbox') {
            foreach ($this->splitAnswerOptions($trimmed) as $selected) {
                if (! in_array($selected, $options, true)) {
                    return 'Invalid data provided';
                }
            }
        }
        return null;
    }

    private function loadOptionsByQuestionId(array $questionIds): array
    {
        if ($questionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $rows = DB::select('SELECT question_id, option_text FROM question_options WHERE question_id IN ('.$placeholders.') ORDER BY position ASC', $questionIds);
        $optionsByQuestionId = [];
        foreach ($rows as $row) {
            $row = $this->rowToArray($row);
            $optionsByQuestionId[(int) $row['question_id']][] = $row['option_text'];
        }
        return $optionsByQuestionId;
    }

    private function isRateLimited(string $key): bool
    {
        $state = Cache::get('public_submission:'.$key);
        return is_array($state) && ((int) ($state['attempts'] ?? 0)) >= 20 && ((int) ($state['window_started'] ?? 0)) >= (time() - 600);
    }

    private function recordRateLimitAttempt(string $key): void
    {
        $cacheKey = 'public_submission:'.$key;
        $now = time();
        $state = Cache::get($cacheKey);
        if (! is_array($state) || ((int) ($state['window_started'] ?? 0)) < ($now - 600)) {
            $state = ['window_started' => $now, 'attempts' => 0];
        }
        $state['attempts'] = ((int) ($state['attempts'] ?? 0)) + 1;
        Cache::put($cacheKey, $state, 600);
    }

    private function rowToArray(object|array $row): array
    {
        return is_array($row) ? $row : get_object_vars($row);
    }
}