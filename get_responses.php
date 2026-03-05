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

// Get form_id from URL parameter
$form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;

if (!$form_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Form ID is required']);
    exit();
}

try {
    // Get form details
    $stmt = $pdo->prepare("SELECT id, title FROM forms WHERE id = ?");
    $stmt->execute([$form_id]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$form) {
        http_response_code(404);
        echo json_encode(['error' => 'Form not found']);
        exit();
    }
    
    // Get all responses for this form
    $stmt = $pdo->prepare("
        SELECT 
            id,
            submitted_at
        FROM responses
        WHERE form_id = ?
        ORDER BY submitted_at DESC
    ");
    $stmt->execute([$form_id]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // For each response, get the answer count
    foreach ($responses as &$response) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as answer_count
            FROM answers
            WHERE response_id = ?
        ");
        $stmt->execute([$response['id']]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        $response['answer_count'] = $count['answer_count'];
    }
    
    // Return success with responses
    echo json_encode([
        'success' => true,
        'form' => $form,
        'responses' => $responses,
        'total_responses' => count($responses)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to retrieve responses',
        'message' => $e->getMessage()
    ]);
}
?>