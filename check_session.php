<?php
require_once 'auth_helper.php';
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

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

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
