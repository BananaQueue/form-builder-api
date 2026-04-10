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

// If the caller provided `slug-uniqueCode`, try the last segment too.
// If the caller provided just `uniqueCode`, this will be the same string.
$unique_code_candidate = $form_code;
if (strpos($form_code, '-') !== false) {
    $parts = explode('-', $form_code);
    $unique_code_candidate = end($parts);
}

try {
    // Backward compatibility for databases that haven't run all migrations yet.
    $formCodeColumnExists = false;
    $questionColumns = [];

    $stmt = $pdo->query("SHOW COLUMNS FROM forms LIKE 'form_code'");
    $formCodeColumnExists = (bool) $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$formCodeColumnExists) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Failed to retrieve form',
            'message' => 'The database is missing the form_code column in forms.'
        ]);
        exit();
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM questions");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $column) {
        $questionColumns[$column['Field']] = true;
    }

    $questionSelectColumns = [
        "id",
        "question_text",
        "question_type",
        isset($questionColumns['rating_scale']) ? "rating_scale" : "NULL AS rating_scale",
        isset($questionColumns['number_min']) ? "number_min" : "NULL AS number_min",
        isset($questionColumns['number_max']) ? "number_max" : "NULL AS number_max",
        isset($questionColumns['number_step']) ? "number_step" : "NULL AS number_step",
        isset($questionColumns['datetime_type']) ? "datetime_type" : "NULL AS datetime_type",
        "position",
        isset($questionColumns['is_required']) ? "is_required" : "1 AS is_required",
        isset($questionColumns['condition_question_id']) ? "condition_question_id" : "NULL AS condition_question_id",
        isset($questionColumns['condition_type']) ? "condition_type" : "'equals' AS condition_type",
        isset($questionColumns['condition_value']) ? "condition_value" : "NULL AS condition_value",
    ];
    $questionSelectSql = implode(",\n            ", $questionSelectColumns);

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

    // 1) Exact match: `form_code` equals provided string
    $stmt->execute([$form_code]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2) Exact match: try just the unique tail segment
    if (!$form && $unique_code_candidate !== $form_code) {
        $stmt->execute([$unique_code_candidate]);
        $form = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // 3) Suffix match: db contains `some-slug-$uniqueCode`
    if (!$form) {
        $stmtLike = $pdo->prepare("
            SELECT 
                f.id,
                f.title,
                f.description,
                f.category_id,
                c.name as category_name,
                f.created_at
            FROM forms f
            LEFT JOIN categories c ON f.category_id = c.id
            WHERE f.form_code LIKE ?
        ");
        $stmtLike->execute(['%-' . $unique_code_candidate]);
        $form = $stmtLike->fetch(PDO::FETCH_ASSOC);
    }

    if (!$form) {
        http_response_code(404);
        echo json_encode(['error' => 'Form not found']);
        exit();
    }
    
    // Get questions for this form
    $stmt = $pdo->prepare("
        SELECT 
            {$questionSelectSql}
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