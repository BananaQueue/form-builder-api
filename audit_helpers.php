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

function fb_ensure_audit_logs_table(PDO $pdo): bool
{
    static $ensured = null;
    if ($ensured !== null) {
        return $ensured;
    }

    if (fb_audit_logs_table_exists($pdo)) {
        $ensured = true;
        return true;
    }

    if ($pdo->inTransaction()) {
        error_log('Audit table is missing and cannot be created inside an active transaction');
        $ensured = false;
        return false;
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS audit_logs (
              id INT(11) NOT NULL AUTO_INCREMENT,
              actor_user_id INT(11) DEFAULT NULL,
              actor_username VARCHAR(100) DEFAULT NULL,
              actor_role VARCHAR(50) DEFAULT NULL,
              action VARCHAR(80) NOT NULL,
              entity_type VARCHAR(80) DEFAULT NULL,
              entity_id INT(11) DEFAULT NULL,
              entity_label VARCHAR(255) DEFAULT NULL,
              metadata LONGTEXT DEFAULT NULL,
              ip_address VARCHAR(45) DEFAULT NULL,
              user_agent VARCHAR(255) DEFAULT NULL,
              created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_audit_actor_created (actor_user_id, created_at),
              KEY idx_audit_action_created (action, created_at),
              KEY idx_audit_entity (entity_type, entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        $ensured = true;
    } catch (Throwable $e) {
        error_log('Audit table creation failed: ' . $e->getMessage());
        $ensured = false;
    }

    return $ensured;
}

function fb_audit_log(PDO $pdo, string $action, array $data = []): bool
{
    if (!fb_ensure_audit_logs_table($pdo)) {
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
