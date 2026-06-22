<?php
require_once 'auth_helper.php';
require_once 'db.php';
require_once 'audit_helpers.php';
fb_send_security_headers();
fb_start_session();

require_once 'cors_helper.php';
fb_apply_cors('POST, OPTIONS', 'Content-Type, X-CSRF-Token', 'application/json');
fb_exit_on_options();

// Destroy the session — wipe the server-side locker clean
fb_require_csrf();

if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    fb_audit_log($pdo, 'USER_LOGOUT', [
        'entity_type' => 'user',
        'entity_id' => $_SESSION['user_id'] ?? null,
        'entity_label' => $_SESSION['username'] ?? null,
    ]);
}

session_destroy();

echo json_encode(['success' => true]);
?>
