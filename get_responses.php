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

// Get form_id from URL parameter
$form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;

if (!$form_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Form ID is required']);
    exit();
}

try {
    // Get form details
    $stmt = $pdo->prepare("SELECT id, title, created_by FROM forms WHERE id = ?");
    $stmt->execute([$form_id]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$form) {
        http_response_code(404);
        echo json_encode(['error' => 'Form not found']);
        exit();
    }

    $formOwnerId = (int) ($form['created_by'] ?? 0);
    if (!$isSuperAdmin && $formOwnerId !== $currentUserId) {
        http_response_code(403);
        echo json_encode(['error' => 'You can only view responses for your own forms']);
        exit();
    }
    unset($form['created_by']);
    
    // Only return responses that have at least one saved answer.
    $stmt = $pdo->prepare("
        SELECT 
            r.id,
            r.submitted_at,
            COUNT(a.id) AS answer_count
        FROM responses r
        INNER JOIN answers a ON a.response_id = r.id
        WHERE r.form_id = ?
        GROUP BY r.id, r.submitted_at
        ORDER BY r.submitted_at DESC
    ");
    $stmt->execute([$form_id]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return success with responses
    echo json_encode([
        'success' => true,
        'form' => $form,
        'responses' => $responses,
        'total_responses' => count($responses)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    error_log($e->getMessage());
    echo json_encode([
        'error' => 'Failed to retrieve responses'
    ]);
}
?>
