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

$data = json_decode(file_get_contents('php://input'), true);
$notificationId = (int) ($data['notification_id'] ?? 0);

if (!$notificationId) {
    http_response_code(400);
    echo json_encode(['error' => 'notification_id is required']);
    exit();
}

if (!fb_notifications_table_exists($pdo)) {
    echo json_encode(['success' => true]);
    exit();
}

try {
    $stmt = $pdo->prepare("
        UPDATE notifications
        SET is_read = 1
        WHERE id = ? AND recipient_user_id = ?
    ");
    $stmt->execute([$notificationId, $userId]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['error' => 'Notification not found']);
        exit();
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to mark notification as read', 'message' => $e->getMessage()]);
}
