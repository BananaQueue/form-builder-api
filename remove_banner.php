<?php
require_once 'auth_helper.php';
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

fb_require_auth();
fb_require_csrf();
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$dest = __DIR__ . '/uploads/banner.png';

if (!file_exists($dest)) {
    echo json_encode(['success' => false, 'error' => 'No banner to remove']);
    exit();
}

if (!unlink($dest)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to remove banner']);
    exit();
}

fb_audit_log($pdo, 'BANNER_REMOVED', [
    'entity_type' => 'banner',
    'entity_label' => 'banner.png',
]);

echo json_encode(['success' => true]);
?>
