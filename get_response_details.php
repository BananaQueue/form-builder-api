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
require_once 'auth_helper.php';

$currentUserId = fb_require_auth();
$isSuperAdmin = !empty($_SESSION['role']) && $_SESSION['role'] === 'super_admin';

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
            f.title as form_title,
            f.created_by
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

    $formOwnerId = (int) ($response['created_by'] ?? 0);
    if (!$isSuperAdmin && $formOwnerId !== $currentUserId) {
        http_response_code(403);
        echo json_encode(['error' => 'You can only view responses for your own forms']);
        exit();
    }
    unset($response['created_by']);
    
    // Read from answers first; enrich with live question data when available.
    // Snapshots on answers (migration 010) keep labels when questions change.
    $answerColumns = [];
    foreach ($pdo->query("SHOW COLUMNS FROM answers")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $answerColumns[$column['Field']] = true;
    }

    $questionTextExpr = isset($answerColumns['question_text'])
        ? "COALESCE(q.question_text, a.question_text, CONCAT('Question #', a.question_id))"
        : "COALESCE(q.question_text, CONCAT('Question #', a.question_id))";

    $questionTypeExpr = isset($answerColumns['question_type'])
        ? "COALESCE(q.question_type, a.question_type, 'text')"
        : "COALESCE(q.question_type, 'text')";

    $stmt = $pdo->prepare("
        SELECT 
            a.id,
            a.question_id,
            a.answer_text,
            {$questionTextExpr} AS question_text,
            {$questionTypeExpr} AS question_type,
            COALESCE(q.position, a.id) AS sort_position
        FROM answers a
        LEFT JOIN questions q ON a.question_id = q.id
        WHERE a.response_id = ?
        ORDER BY sort_position ASC, a.id ASC
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
    error_log($e->getMessage());
    echo json_encode([
        'error' => 'Failed to retrieve response details'
    ]);
}
?>
