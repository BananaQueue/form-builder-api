<?php
require_once 'auth_helper.php';
require_once 'db.php';
require_once 'audit_helpers.php';

fb_send_security_headers();

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

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$currentUserId = fb_require_super_admin();
fb_require_csrf();

$data   = json_decode(file_get_contents('php://input'), true);
$userId = isset($data['user_id']) ? (int) $data['user_id'] : 0;

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
    exit();
}

if ($userId === $currentUserId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'You cannot delete your own account']);
    exit();
}

try {
    $lookup = $pdo->prepare("SELECT username, role FROM users WHERE id = ?");
    $lookup->execute([$userId]);
    $targetUser = $lookup->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit();
    }

    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$userId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit();
    }

    fb_audit_log($pdo, 'USER_DELETED', [
        'entity_type' => 'user',
        'entity_id' => $userId,
        'entity_label' => $targetUser['username'],
        'metadata' => ['role' => $targetUser['role']],
    ]);

    // Their forms remain in the database with created_by = NULL
    // (ON DELETE SET NULL constraint from migration 007)
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to delete user']);
}
?>
