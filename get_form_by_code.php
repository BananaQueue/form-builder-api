<?php
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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';

// Get form_code from URL parameter
$form_code = isset($_GET['code']) ? trim($_GET['code']) : '';

if (!$form_code) {
    http_response_code(400);
    echo json_encode(['error' => 'Form code is required']);
    exit();
}

try {
    // Get form details by code
    $stmt = $pdo->prepare("
        SELECT 
            f.id,
            f.title,
            f.description,
            f.category_id,
            c.name as category_name,
            f.created_at
        FROM forms f
        LEFT JOIN categories c ON f.category_id = c.id
        WHERE f.form_code = ?
    ");
    $stmt->execute([$form_code]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$form) {
        http_response_code(404);
        echo json_encode(['error' => 'Form not found']);
        exit();
    }
    
    // Get questions for this form
    $stmt = $pdo->prepare("
        SELECT 
            id,
            question_text,
            question_type,
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
        WHERE form_id = ?
        ORDER BY position ASC
    ");
    $stmt->execute([$form['id']]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // For each question, get its options
    foreach ($questions as &$question) {
        $stmt = $pdo->prepare("
            SELECT 
                option_text,
                position
            FROM question_options
            WHERE question_id = ?
            ORDER BY position ASC
        ");
        $stmt->execute([$question['id']]);
        $options = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $question['options'] = array_map(function($opt) {
            return $opt['option_text'];
        }, $options);
    }
    
    $form['questions'] = $questions;
    
    echo json_encode([
        'success' => true,
        'form' => $form
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to retrieve form',
        'message' => $e->getMessage()
    ]);
}
?>