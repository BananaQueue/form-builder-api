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
    
    // Get all questions for this form (to build CSV headers)
    $stmt = $pdo->prepare("
        SELECT id, question_text, position
        FROM questions
        WHERE form_id = ?
        ORDER BY position ASC
    ");
    $stmt->execute([$form_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all responses
    $stmt = $pdo->prepare("
        SELECT id, submitted_at
        FROM responses
        WHERE form_id = ?
        ORDER BY submitted_at DESC
    ");
    $stmt->execute([$form_id]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $form['title'] . '_responses_' . date('Y-m-d') . '.csv"');
    
    // Create output stream
    $output = fopen('php://output', 'w');
    
    // Write CSV header row
    $headers = ['Response ID', 'Submitted At'];
    foreach ($questions as $question) {
        $headers[] = $question['question_text'];
    }
    fputcsv($output, $headers);
    
    // Write data rows
    foreach ($responses as $response) {
        $row = [$response['id'], $response['submitted_at']];
        
        // Get answers for this response
        foreach ($questions as $question) {
            $stmt = $pdo->prepare("
                SELECT answer_text
                FROM answers
                WHERE response_id = ? AND question_id = ?
            ");
            $stmt->execute([$response['id'], $question['id']]);
            $answer = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $row[] = $answer ? $answer['answer_text'] : '';
        }
        
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to export responses',
        'message' => $e->getMessage()
    ]);
}
?>