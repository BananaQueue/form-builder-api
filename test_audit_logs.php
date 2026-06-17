<?php
require_once 'db.php';

header('Content-Type: application/json');

function fb_test_audit_json_error(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    fb_test_audit_json_error(405, 'Method not allowed');
}

$guardEnabled = !empty($fbDbConfig['allow_test_guard']);
if (!$guardEnabled) {
    fb_test_audit_json_error(404, 'Not found');
}

$databaseName = (string) ($fbDbConfig['dbname'] ?? '');
$isTestDatabase = preg_match('/(^|_)test$/i', $databaseName) === 1;
if (!$isTestDatabase) {
    fb_test_audit_json_error(500, 'Audit test lookup must use a dedicated test database.');
}

$expectedToken = (string) ($fbDbConfig['test_reset_token'] ?? getenv('FB_TEST_RESET_TOKEN') ?: '');
$providedToken = $_SERVER['HTTP_X_E2E_RESET_TOKEN'] ?? '';
if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    fb_test_audit_json_error(403, 'Invalid test token');
}

$action = trim($_GET['action'] ?? '');
$entityLabel = trim($_GET['entity_label'] ?? '');

try {
    $where = [];
    $params = [];

    if ($action !== '') {
        $where[] = 'action = ?';
        $params[] = $action;
    }

    if ($entityLabel !== '') {
        $where[] = 'entity_label = ?';
        $params[] = $entityLabel;
    }

    $whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare("
        SELECT
            id,
            actor_user_id,
            actor_username,
            actor_role,
            action,
            entity_type,
            entity_id,
            entity_label,
            metadata,
            created_at
        FROM audit_logs
        {$whereSql}
        ORDER BY id DESC
        LIMIT 20
    ");
    $stmt->execute($params);

    echo json_encode([
        'ok' => true,
        'database' => $databaseName,
        'logs' => $stmt->fetchAll(PDO::FETCH_ASSOC),
    ]);
} catch (Throwable $e) {
    error_log('Audit test lookup failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Audit test lookup failed',
        'details' => $e->getMessage(),
    ]);
}
