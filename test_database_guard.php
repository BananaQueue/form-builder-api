<?php
require_once 'db.php';

header('Content-Type: application/json');

$guardEnabled = !empty($fbDbConfig['allow_test_guard']);
if (!$guardEnabled) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

$databaseName = (string) ($fbDbConfig['dbname'] ?? '');
$isTestDatabase = preg_match('/(^|_)test$/i', $databaseName) === 1;

if (!$isTestDatabase) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'E2E tests must use a dedicated test database.',
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'database' => $databaseName,
]);
