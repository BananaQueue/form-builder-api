<?php

function fb_audit_logs_table_exists(PDO $pdo): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }

    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'audit_logs'");
        $exists = (bool) $stmt->fetch(PDO::FETCH_NUM);
    } catch (Throwable $e) {
        error_log('Audit table check failed: ' . $e->getMessage());
        $exists = false;
    }

    return $exists;
}

function fb_audit_log(PDO $pdo, string $action, array $data = []): bool
{
    if (!fb_audit_logs_table_exists($pdo)) {
        return false;
    }

    $metadata = $data['metadata'] ?? null;
    if ($metadata !== null && !is_string($metadata)) {
        $metadata = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (
                actor_user_id,
                actor_username,
                actor_role,
                action,
                entity_type,
                entity_id,
                entity_label,
                metadata,
                ip_address,
                user_agent
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $_SESSION['username'] ?? null,
            $_SESSION['role'] ?? null,
            $action,
            $data['entity_type'] ?? null,
            isset($data['entity_id']) ? (int) $data['entity_id'] : null,
            $data['entity_label'] ?? null,
            $metadata,
            function_exists('fb_client_ip') ? fb_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? null),
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);

        return true;
    } catch (Throwable $e) {
        error_log('Audit log write failed: ' . $e->getMessage());
        return false;
    }
}
?>
