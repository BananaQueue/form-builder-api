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

header('Access-Control-Allow-Methods: POST, PUT, OPTIONS');
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
if (!$data || !isset($data['form_id']) || !isset($data['title']) || !isset($data['questions'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data provided']);
    exit();
}

$formId = $data['form_id'];
$title = $data['title'];
$description = $data['description'] ?? '';
$categoryId = $data['category_id'] ?? 1;
$questions = $data['questions'];

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // Update form details
    $stmt = $pdo->prepare("UPDATE forms SET title = ?, description = ?, category_id = ? WHERE id = ?");
    $stmt->execute([$title, $description, $categoryId, $formId]);
    
    // Delete existing questions and options (CASCADE will handle options)
    $stmt = $pdo->prepare("DELETE FROM questions WHERE form_id = ?");
    $stmt->execute([$formId]);
    
    // Insert updated questions
    $questionStmt = $pdo->prepare("INSERT INTO questions (form_id, question_text, question_type, position, is_required) VALUES (?, ?, ?, ?, ?)");
    $optionStmt = $pdo->prepare("INSERT INTO question_options (question_id, option_text, position) VALUES (?, ?, ?)");
    
    foreach ($questions as $index => $question) {
        // Insert question
        $questionStmt->execute([
            $formId,
            $question['text'],
            $question['type'],
            $index,
            $question['is_required'] ?? 1
        ]);
        
        // Get the ID of the inserted question
        $questionId = $pdo->lastInsertId();
        
        // Insert options if they exist
        if (isset($question['options']) && is_array($question['options'])) {
            foreach ($question['options'] as $optIndex => $option) {
                $optionStmt->execute([
                    $questionId,
                    $option,
                    $optIndex
                ]);
            }
        }
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Form updated successfully',
        'form_id' => $formId
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to update form',
        'message' => $e->getMessage()
    ]);
}
?>