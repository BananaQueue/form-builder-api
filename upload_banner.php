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

if (empty($_FILES['banner']) || $_FILES['banner']['error'] === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit();
}

$file = $_FILES['banner'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Upload error code: ' . $file['error']]);
    exit();
}

$maxBytes = 2 * 1024 * 1024;
if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxBytes) {
    echo json_encode(['success' => false, 'error' => 'PNG file must be 2 MB or smaller']);
    exit();
}

// Validate MIME type — don't trust the browser's file extension alone
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if ($mimeType !== 'image/png') {
    echo json_encode(['success' => false, 'error' => 'Only PNG files are allowed']);
    exit();
}

$imageInfo = @getimagesize($file['tmp_name']);
if (!$imageInfo || ($imageInfo[2] ?? null) !== IMAGETYPE_PNG) {
    echo json_encode(['success' => false, 'error' => 'Only PNG files are allowed']);
    exit();
}

$uploadDir = __DIR__ . '/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$dest = $uploadDir . 'banner.png';
if (!move_uploaded_file($file['tmp_name'], $dest)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
    exit();
}

fb_audit_log($pdo, 'BANNER_UPLOADED', [
    'entity_type' => 'banner',
    'entity_label' => 'banner.png',
    'metadata' => [
        'size' => (int) ($file['size'] ?? 0),
        'mime_type' => $mimeType,
    ],
]);

echo json_encode(['success' => true]);
?>
