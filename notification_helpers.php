<?php

function fb_notifications_table_exists(PDO $pdo): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    $stmt = $pdo->query("SHOW TABLES LIKE 'notifications'");
    $exists = (bool) $stmt->fetch(PDO::FETCH_NUM);
    return $exists;
}

function fb_notification_cors_headers(): void
{
    $allowed_origins = [
        'http://localhost:5173',
        'http://localhost:5174',
        'http://localhost',
        'http://formbuilder.local',
        'http://127.0.0.1:5173',
    ];

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowed_origins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }

    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    header('Content-Type: application/json');
}

if (!function_exists('fb_is_super_admin_session')) {
    function fb_is_super_admin_session(): bool
    {
        return !empty($_SESSION['role']) && $_SESSION['role'] === 'super_admin';
    }
}

function fb_create_form_notification(PDO $pdo, array $data): bool
{
    if (!fb_notifications_table_exists($pdo)) {
        return false;
    }

    $type = $data['type'] ?? '';
    if (!in_array($type, ['FORM_EDITED', 'FORM_DELETED'], true)) {
        return false;
    }

    $recipientId = (int) ($data['recipient_user_id'] ?? 0);
    $formTitle = trim((string) ($data['form_title'] ?? ''));
    if ($recipientId <= 0 || $formTitle === '') {
        return false;
    }

    $message = $data['message'] ?? '';
    if ($message === '') {
        if ($type === 'FORM_EDITED') {
            $message = "Your form '{$formTitle}' was reviewed and edited by a Super Administrator.";
        } else {
            $message = "Your form '{$formTitle}' was removed by a Super Administrator.";
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO notifications (
            recipient_user_id,
            type,
            form_id,
            form_title,
            message,
            deletion_reason,
            admin_id,
            admin_name
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->execute([
        $recipientId,
        $type,
        isset($data['form_id']) ? (int) $data['form_id'] : null,
        $formTitle,
        $message,
        $data['deletion_reason'] ?? null,
        isset($data['admin_id']) ? (int) $data['admin_id'] : null,
        $data['admin_name'] ?? null,
    ]);

    return true;
}

function fb_map_notification_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'recipientUserId' => (int) $row['recipient_user_id'],
        'type' => $row['type'],
        'formId' => $row['form_id'] !== null ? (int) $row['form_id'] : null,
        'formTitle' => $row['form_title'],
        'message' => $row['message'],
        'deletionReason' => $row['deletion_reason'],
        'adminId' => $row['admin_id'] !== null ? (int) $row['admin_id'] : null,
        'adminName' => $row['admin_name'],
        'createdAt' => $row['created_at'],
        'read' => (bool) $row['is_read'],
        'acknowledged' => (bool) $row['acknowledged'],
    ];
}
