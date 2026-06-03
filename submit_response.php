<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// CORS headers
$allowed_origins = [
    'http://localhost:5173',
    'http://localhost:5174',
    'http://localhost',
    'http://formbuilder.local',
    'http://127.0.0.1:5173',
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

if (in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database connection
require_once 'db.php';

// Get JSON data
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validate data
if (!$data || !isset($data['form_id']) || !isset($data['answers']) || !is_array($data['answers'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data provided']);
    exit();
}

$formId = (int) $data['form_id'];
$answers = $data['answers'];

try {
    $answerColumns = [];
    foreach ($pdo->query("SHOW COLUMNS FROM answers")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $answerColumns[$column['Field']] = true;
    }

    $hasQuestionSnapshot = isset($answerColumns['question_text']) && isset($answerColumns['question_type']);

    $questionColumns = [];
    foreach ($pdo->query("SHOW COLUMNS FROM questions")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $questionColumns[$column['Field']] = true;
    }
    $activeFilter = isset($questionColumns['is_active']) ? ' AND is_active = 1' : '';

    $questionStmt = $pdo->prepare("
        SELECT id, question_text, question_type
        FROM questions
        WHERE form_id = ?{$activeFilter}
    ");
    $questionStmt->execute([$formId]);
    $questions = [];
    foreach ($questionStmt->fetchAll(PDO::FETCH_ASSOC) as $question) {
        $questions[(int) $question['id']] = $question;
    }

    if (count($questions) === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Form not found or has no active questions']);
        exit();
    }

    // Start transaction
    $pdo->beginTransaction();
    
    // Insert response record
    $stmt = $pdo->prepare("INSERT INTO responses (form_id) VALUES (?)");
    $stmt->execute([$formId]);
    
    // Get the response ID
    $responseId = $pdo->lastInsertId();
    
    if ($hasQuestionSnapshot) {
        $stmt = $pdo->prepare("
            INSERT INTO answers (response_id, question_id, question_text, question_type, answer_text)
            VALUES (?, ?, ?, ?, ?)
        ");
    } else {
        $stmt = $pdo->prepare("INSERT INTO answers (response_id, question_id, answer_text) VALUES (?, ?, ?)");
    }
    
    foreach ($answers as $answer) {
        $questionId = (int) ($answer['question_id'] ?? 0);
        if (!isset($questions[$questionId])) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Submission contains a question that does not belong to this form']);
            exit();
        }

        $questionRow = $questions[$questionId];
        $answerText = is_scalar($answer['answer_text'] ?? null)
            ? (string) $answer['answer_text']
            : '';

        if ($hasQuestionSnapshot) {
            $questionText = $questionRow['question_text'] ?? 'Question #' . $questionId;
            $questionType = $questionRow['question_type'] ?? 'text';

            $stmt->execute([$responseId, $questionId, $questionText, $questionType, $answerText]);
        } else {
            $stmt->execute([$responseId, $questionId, $answerText]);
        }
    }
    
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM answers WHERE response_id = ?");
    $countStmt->execute([$responseId]);
    if ((int) $countStmt->fetchColumn() === 0) {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'No answers were saved for this submission']);
        exit();
    }

    // Commit transaction
    $pdo->commit();
    
    // Return success
    echo json_encode([
        'success' => true,
        'message' => 'Response submitted successfully',
        'response_id' => $responseId
    ]);
    
} catch (Exception $e) {
    // Rollback on error
    $pdo->rollBack();
    
    http_response_code(500);
    error_log($e->getMessage());
    echo json_encode([
        'error' => 'Failed to submit response'
    ]);
}
?>
