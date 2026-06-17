<?php
require_once 'auth_helper.php';
require_once 'db.php';
require_once 'audit_helpers.php';
fb_send_security_headers();
fb_start_session();

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
