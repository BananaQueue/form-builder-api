<?php
require_once 'db.php';

header('Content-Type: application/json');

function fb_test_json_error(int $status, string $message): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fb_test_json_error(405, 'Method not allowed');
}

$guardEnabled = !empty($fbDbConfig['allow_test_guard']);
if (!$guardEnabled) {
    fb_test_json_error(404, 'Not found');
}

$databaseName = (string) ($fbDbConfig['dbname'] ?? '');
$isTestDatabase = preg_match('/(^|_)test$/i', $databaseName) === 1;
if (!$isTestDatabase) {
    fb_test_json_error(500, 'E2E reset must use a dedicated test database.');
}

$expectedToken = (string) ($fbDbConfig['test_reset_token'] ?? getenv('FB_TEST_RESET_TOKEN') ?: '');
$providedToken = $_SERVER['HTTP_X_E2E_RESET_TOKEN'] ?? '';
if ($expectedToken === '' || !hash_equals($expectedToken, $providedToken)) {
    fb_test_json_error(403, 'Invalid reset token');
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

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    foreach ([
        'answers',
        'responses',
        'question_options',
        'questions',
        'notifications',
        'forms',
        'audit_logs',
        'users',
        'categories',
    ] as $table) {
        $pdo->exec("DELETE FROM `$table`");
        $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1");
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    $categoryStmt = $pdo->prepare('INSERT INTO categories (id, name) VALUES (?, ?)');
    foreach ([
        [1, 'General'],
        [2, 'External'],
        [3, 'Internal'],
    ] as $category) {
        $categoryStmt->execute($category);
    }

    $userStmt = $pdo->prepare(
        'INSERT INTO users (id, username, role, password_hash) VALUES (?, ?, ?, ?)'
    );

    $passwordHash = password_hash('PlaywrightTest123!', PASSWORD_DEFAULT);
    $userStmt->execute([1, 'e2e_super_admin', 'super_admin', $passwordHash]);
    $userStmt->execute([2, 'e2e_regular_user', 'user', $passwordHash]);
    $userStmt->execute([3, 'e2e_regular_user_two', 'user', $passwordHash]);

    echo json_encode([
        'ok' => true,
        'database' => $databaseName,
        'seeded' => [
            'users' => ['e2e_super_admin', 'e2e_regular_user', 'e2e_regular_user_two'],
            'categories' => ['General', 'External', 'Internal'],
        ],
    ]);
} catch (Throwable $e) {
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    error_log('Test database reset failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Test database reset failed',
        'details' => $e->getMessage(),
    ]);
    exit;
}
