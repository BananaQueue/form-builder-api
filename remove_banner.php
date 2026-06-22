<?php
require_once 'auth_helper.php';
require_once 'audit_helpers.php';
fb_send_security_headers();

require_once 'cors_helper.php';
fb_apply_cors('POST, OPTIONS', 'Content-Type, X-CSRF-Token', 'application/json');
fb_exit_on_options();

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
