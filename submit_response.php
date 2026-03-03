<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
if (!$data || !isset($data['form_id']) || !isset($data['answers'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data provided']);
    exit();
}

$formId = $data['form_id'];
$answers = $data['answers'];

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // Insert response record
    $stmt = $pdo->prepare("INSERT INTO responses (form_id) VALUES (?)");
    $stmt->execute([$formId]);
    
    // Get the response ID
    $responseId = $pdo->lastInsertId();
    
    // Insert each answer
    $stmt = $pdo->prepare("INSERT INTO answers (response_id, question_id, answer_text) VALUES (?, ?, ?)");
    
    foreach ($answers as $answer) {
        $questionId = $answer['question_id'];
        $answerText = $answer['answer_text'];
        
        $stmt->execute([$responseId, $questionId, $answerText]);
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
    echo json_encode([
        'error' => 'Failed to submit response',
        'message' => $e->getMessage()
    ]);
}
?>