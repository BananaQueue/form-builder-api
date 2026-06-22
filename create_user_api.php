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

$data = json_decode(file_get_contents('php://input'), true);

$username = trim($data['username'] ?? '');
$password = $data['password'] ?? '';
$role     = $data['role'] ?? 'user';

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Username and password are required']);
    exit();
}

if (!in_array($role, ['user', 'super_admin'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid role']);
    exit();
}

$passwordError = fb_password_policy_error($password);
if ($passwordError) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $passwordError]);
    exit();
}

try {
    $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([$username]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Username already exists']);
        exit();
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO users (username, role, password_hash) VALUES (?, ?, ?)");
    $stmt->execute([$username, $role, $hash]);
    $newUserId = (int) $pdo->lastInsertId();

    fb_audit_log($pdo, 'USER_CREATED', [
        'entity_type' => 'user',
        'entity_id' => $newUserId,
        'entity_label' => $username,
        'metadata' => ['role' => $role],
    ]);

    echo json_encode(['success' => true, 'user_id' => $newUserId]);

} catch (Exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to create user']);
}
?>
