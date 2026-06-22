<?php
require_once 'auth_helper.php';
require_once 'db.php';
require_once 'audit_helpers.php';

fb_send_security_headers();

require_once 'cors_helper.php';
fb_apply_cors('POST, OPTIONS', 'Content-Type, X-CSRF-Token', 'application/json');
fb_exit_on_options();

fb_require_super_admin();
fb_require_csrf();

$data        = json_decode(file_get_contents('php://input'), true);
$userId      = isset($data['user_id']) ? (int) $data['user_id'] : 0;
$newPassword = $data['new_password'] ?? '';

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
    exit();
}

$passwordError = fb_password_policy_error($newPassword);
if ($passwordError) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $passwordError]);
    exit();
}

try {
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->execute([$hash, $userId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit();
    }

    fb_audit_log($pdo, 'USER_PASSWORD_CHANGED', [
        'entity_type' => 'user',
        'entity_id' => $userId,
    ]);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to update password']);
}
?>
