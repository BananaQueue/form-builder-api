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
require_once 'form_question_fetch_helpers.php';

// Get form_id from URL parameter
$form_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$form_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Form ID is required']);
    exit();
}

try {
    // Backward compatibility for databases that haven't run all migrations yet.
    $formCodeColumnExists = false;
    $questionColumns = [];

    $stmt = $pdo->query("SHOW COLUMNS FROM forms LIKE 'form_code'");
    $formCodeColumnExists = (bool) $stmt->fetch(PDO::FETCH_ASSOC);

    $questionColumns = fb_get_question_columns($pdo);

    $formCodeSelect = $formCodeColumnExists ? "f.form_code," : "NULL AS form_code,";
    $questionSelectSql = fb_build_question_select_sql($questionColumns);

    // Get form details
    $stmt = $pdo->prepare("
        SELECT 
            f.id,
            {$formCodeSelect}
            f.title,
            f.description,
            f.category_id,
            c.name as category_name,
            f.created_at
        FROM forms f
        LEFT JOIN categories c ON f.category_id = c.id
        WHERE f.id = ?
    ");
    $stmt->execute([$form_id]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$form) {
        http_response_code(404);
        echo json_encode(['error' => 'Form not found']);
        exit();
    }

    // Get questions for this form (with options)
    $questions = fb_fetch_questions_with_options($pdo, $form_id, $questionSelectSql);

    // Add questions to form data
    $form['questions'] = $questions;

    // Return success with form data
    echo json_encode([
        'success' => true,
        'form' => $form
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to retrieve form details',
        'message' => $e->getMessage()
    ]);
}
