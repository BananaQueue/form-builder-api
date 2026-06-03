<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'db.php';
require_once 'auth_helper.php';
require_once 'notification_helpers.php';

fb_notification_cors_headers();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$userId = fb_require_auth();

if (!fb_notifications_table_exists($pdo)) {
    echo json_encode(['success' => true, 'notifications' => [], 'pending_count' => 0]);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT *
        FROM notifications
        WHERE recipient_user_id = ? AND acknowledged = 0
        ORDER BY created_at ASC
    ");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'notifications' => array_map('fb_map_notification_row', $rows),
        'pending_count' => count($rows),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to retrieve pending notifications', 'message' => $e->getMessage()]);
}
