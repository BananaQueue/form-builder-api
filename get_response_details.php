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

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database connection
require_once 'db.php';

// Get response_id from URL parameter
$response_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$response_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Response ID is required']);
    exit();
}

try {
    // Get response details
    $stmt = $pdo->prepare("
        SELECT 
            r.id,
            r.form_id,
            r.submitted_at,
            f.title as form_title
        FROM responses r
        JOIN forms f ON r.form_id = f.id
        WHERE r.id = ?
    ");
    $stmt->execute([$response_id]);
    $response = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$response) {
        http_response_code(404);
        echo json_encode(['error' => 'Response not found']);
        exit();
    }
    
    // Get all answers for this response with question details
    $stmt = $pdo->prepare("
        SELECT 
            a.id,
            a.question_id,
            a.answer_text,
            q.question_text,
            q.question_type
        FROM answers a
        JOIN questions q ON a.question_id = q.id
        WHERE a.response_id = ?
        ORDER BY q.position ASC
    ");
    $stmt->execute([$response_id]);
    $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add answers to response
    $response['answers'] = $answers;
    
    // Return success with response details
    echo json_encode([
        'success' => true,
        'response' => $response
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to retrieve response details',
        'message' => $e->getMessage()
    ]);
}
?>