<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// CORS headers
require_once 'cors_helper.php';
fb_apply_cors('POST, OPTIONS', 'Content-Type', 'application/json');
fb_exit_on_options();

require_once 'db.php';
require_once 'auth_helper.php';

fb_send_security_headers();

function fb_normalize_answer_text($value): string
{
    if (is_array($value)) {
        $value = implode(',', array_map('strval', $value));
    }

    return substr((string) ($value ?? ''), 0, 20000);
}

function fb_split_answer_options(string $value): array
{
    if (trim($value) === '') {
        return [];
    }

    return array_map('trim', explode(',', $value));
}

function fb_is_question_visible(array $question, array $answersByQuestionId, array $questionsById): bool
{
    $conditionQuestionId = $question['condition_question_id'] ?? null;
    if (!$conditionQuestionId) {
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
    $parentType = $parentQuestion['question_type'] ?? '';

    if ($parentType === 'checkbox') {
        $selected = fb_split_answer_options($conditionAnswer);
        if (in_array($conditionType, ['contains', 'option_selected', 'equals'], true)) {
            return in_array($conditionValue, $selected, true);
        }
        if (in_array($conditionType, ['not_contains', 'not_equals'], true)) {
            return !in_array($conditionValue, $selected, true);
        }
    }

    if ($conditionType === 'not_equals') {
        return $conditionAnswer !== $conditionValue;
    }

    return $conditionAnswer === $conditionValue;
}

function fb_validate_answer(array $question, string $answerText, array $options): ?string
{
    $type = $question['question_type'];
    $trimmed = trim($answerText);

    if ($trimmed === '' || $type === 'section') {
        return null;
    }

    if ($type === 'email' && !filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
        return 'Invalid data provided';
    }

    if ($type === 'number') {
        if (!is_numeric($trimmed)) {
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
        $dateTimePart = $datePart . 'T' . $timePart;
        $oneValue = '(' . $datePart . '|' . $timePart . '|' . $dateTimePart . ')';
        if (!preg_match('/^' . $oneValue . '( to ' . $oneValue . ')?$/', $trimmed)) {
            return 'Invalid data provided';
        }
        return null;
    }

    if (in_array($type, ['multiple_choice', 'rating'], true)) {
        if (!in_array($trimmed, $options, true)) {
            return 'Invalid data provided';
        }
        return null;
    }

    if ($type === 'checkbox') {
        foreach (fb_split_answer_options($trimmed) as $selected) {
            if (!in_array($selected, $options, true)) {
                return 'Invalid data provided';
            }
        }
    }

    return null;
}

// Get JSON data
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validate data
if (!$data || !isset($data['form_id']) || !isset($data['answers']) || !is_array($data['answers'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data provided']);
    exit();
}

$formId  = $data['form_id'];
$answers = $data['answers'];

$rateLimitKey = (int) $formId . '|' . fb_client_ip();
$rateLimitWindow = 10 * 60;
if (fb_is_rate_limited('public_submission', $rateLimitKey, 20, $rateLimitWindow)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many submissions. Please try again later.']);
    exit();
}

try {
    // Verify the form actually exists before inserting a response row.
    // Without this check, someone could submit responses for a form_id
    // that doesn't exist, which would create orphaned response records.
    $stmt = $pdo->prepare("SELECT id FROM forms WHERE id = ?");
    $stmt->execute([$formId]);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Form not found']);
        exit();
    }

    fb_record_rate_limit_attempt('public_submission', $rateLimitKey, $rateLimitWindow);

    $columnStmt = $pdo->query("SHOW COLUMNS FROM questions");
    $questionColumns = [];
    foreach ($columnStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $questionColumns[$column['Field']] = true;
    }

    $answerColumnStmt = $pdo->query("SHOW COLUMNS FROM answers");
    $answerColumns = [];
    foreach ($answerColumnStmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $answerColumns[$column['Field']] = true;
    }

    $activeFilter = isset($questionColumns['is_active']) ? ' AND is_active = 1' : '';
    $questionSelectColumns = [
        'id',
        'question_text',
        'question_type',
        isset($questionColumns['number_min']) ? 'number_min' : 'NULL AS number_min',
        isset($questionColumns['number_max']) ? 'number_max' : 'NULL AS number_max',
        isset($questionColumns['is_required']) ? 'is_required' : '1 AS is_required',
        isset($questionColumns['condition_question_id']) ? 'condition_question_id' : 'NULL AS condition_question_id',
        isset($questionColumns['condition_type']) ? 'condition_type' : "'equals' AS condition_type",
        isset($questionColumns['condition_value']) ? 'condition_value' : 'NULL AS condition_value',
    ];

    $questionStmt = $pdo->prepare("
        SELECT " . implode(', ', $questionSelectColumns) . "
        FROM questions
        WHERE form_id = ?{$activeFilter}
        ORDER BY position ASC
    ");
    $questionStmt->execute([$formId]);
    $formQuestions = $questionStmt->fetchAll(PDO::FETCH_ASSOC);

    $questionsById = [];
    foreach ($formQuestions as $question) {
        $questionsById[(int) $question['id']] = $question;
    }

    $answersByQuestionId = [];
    foreach ($answers as $answer) {
        $questionId = (int) ($answer['question_id'] ?? 0);
        if ($questionId <= 0 || !isset($questionsById[$questionId])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid data provided']);
            exit();
        }
        $answersByQuestionId[$questionId] = fb_normalize_answer_text($answer['answer_text'] ?? '');
    }

    $questionIds = array_keys($questionsById);
    $optionsByQuestionId = [];
    if (count($questionIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $optionStmt = $pdo->prepare("
            SELECT question_id, option_text
            FROM question_options
            WHERE question_id IN ({$placeholders})
            ORDER BY position ASC
        ");
        $optionStmt->execute($questionIds);
        foreach ($optionStmt->fetchAll(PDO::FETCH_ASSOC) as $optionRow) {
            $optionsByQuestionId[(int) $optionRow['question_id']][] = $optionRow['option_text'];
        }
    }

    foreach ($formQuestions as $question) {
        $questionId = (int) $question['id'];
        $answerText = $answersByQuestionId[$questionId] ?? '';
        $visible = fb_is_question_visible($question, $answersByQuestionId, $questionsById);
        $required = $question['is_required'] === 1 || $question['is_required'] === '1' || $question['is_required'] === true;

        if ($visible && $question['question_type'] !== 'section' && $required && trim($answerText) === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid data provided']);
            exit();
        }

        $validationError = $visible
            ? fb_validate_answer($question, $answerText, $optionsByQuestionId[$questionId] ?? [])
            : null;

        if ($validationError) {
            http_response_code(400);
            echo json_encode(['error' => $validationError]);
            exit();
        }
    }

    // Start transaction
    $pdo->beginTransaction();

    // Insert response record
    $stmt = $pdo->prepare("INSERT INTO responses (form_id) VALUES (?)");
    $stmt->execute([$formId]);

    // Get the response ID
    $responseId = $pdo->lastInsertId();

    // Insert each answer. Use the server-fetched question list so submitted
    // question IDs cannot escape the selected form.
    $answerInsertColumns = ['response_id', 'question_id'];
    if (isset($answerColumns['question_text'])) {
        $answerInsertColumns[] = 'question_text';
    }
    if (isset($answerColumns['question_type'])) {
        $answerInsertColumns[] = 'question_type';
    }
    $answerInsertColumns[] = 'answer_text';

    $stmt = $pdo->prepare(sprintf(
        'INSERT INTO answers (%s) VALUES (%s)',
        implode(', ', $answerInsertColumns),
        implode(', ', array_fill(0, count($answerInsertColumns), '?'))
    ));

    foreach ($formQuestions as $question) {
        $questionId = (int) $question['id'];
        $values = [$responseId, $questionId];
        if (isset($answerColumns['question_text'])) {
            $values[] = $question['question_text'];
        }
        if (isset($answerColumns['question_type'])) {
            $values[] = $question['question_type'];
        }
        $values[] = $answersByQuestionId[$questionId] ?? '';
        $stmt->execute($values);
    }

    // Commit transaction
    $pdo->commit();

    // Return success
    echo json_encode([
        'success'     => true,
        'message'     => 'Response submitted successfully',
        'response_id' => $responseId
    ]);

} catch (Exception $e) {
    // Rollback on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    error_log($e->getMessage());
    echo json_encode([
        'error'   => 'Failed to submit response'
    ]);
}
?>
