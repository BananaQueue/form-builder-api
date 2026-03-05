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

header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
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
if (!$data || !isset($data['form_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Form ID is required']);
    exit();
}

$formId = $data['form_id'];

try {
    // Check if form exists
    $stmt = $pdo->prepare("SELECT id FROM forms WHERE id = ?");
    $stmt->execute([$formId]);
    $form = $stmt->fetch();
    
    if (!$form) {
        http_response_code(404);
        echo json_encode(['error' => 'Form not found']);
        exit();
    }
    
    // Delete form (CASCADE will automatically delete questions, options, responses, answers)
    $stmt = $pdo->prepare("DELETE FROM forms WHERE id = ?");
    $stmt->execute([$formId]);
    
    // Return success
    echo json_encode([
        'success' => true,
        'message' => 'Form deleted successfully'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to delete form',
        'message' => $e->getMessage()
    ]);
}
?>