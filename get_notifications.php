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
    echo json_encode(['success' => true, 'notifications' => [], 'unread_count' => 0, 'pending_count' => 0]);
    exit();
}

try {
    $type = strtoupper(trim($_GET['type'] ?? ''));
    $conditions = ['recipient_user_id = ?'];
    $params = [$userId];

    if ($type === 'FORM_EDITED' || $type === 'FORM_DELETED') {
        $conditions[] = 'type = ?';
        $params[] = $type;
    }

    $where = implode(' AND ', $conditions);

    $stmt = $pdo->prepare("
        SELECT *
        FROM notifications
        WHERE {$where}
        ORDER BY created_at DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $notifications = array_map('fb_map_notification_row', $rows);

    $countStmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread_count,
            SUM(CASE WHEN acknowledged = 0 THEN 1 ELSE 0 END) AS pending_count
        FROM notifications
        WHERE recipient_user_id = ?
    ");
    $countStmt->execute([$userId]);
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'unread_count' => (int) ($counts['unread_count'] ?? 0),
        'pending_count' => (int) ($counts['pending_count'] ?? 0),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to retrieve notifications', 'message' => $e->getMessage()]);
}
