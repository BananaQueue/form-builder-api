<?php
require_once 'auth_helper.php';
fb_send_security_headers();
fb_start_session();

require_once 'cors_helper.php';
fb_apply_cors('GET, OPTIONS', 'Content-Type, X-CSRF-Token', 'application/json');
fb_exit_on_options();

// If the session has logged_in set to true, the user is authenticated.
if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    echo json_encode([
        'logged_in' => true,
        'username'  => $_SESSION['username'],
        'user_id'   => $_SESSION['user_id'],
        'role'      => $_SESSION['role'] ?? 'user',
        'csrf_token' => fb_get_csrf_token(),
    ]);
} else {
    echo json_encode(['logged_in' => false]);
}
?>
