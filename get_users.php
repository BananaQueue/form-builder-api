<?php
require_once 'auth_helper.php';
require_once 'db.php';

fb_send_security_headers();

$allowed_origins = [
    'http://localhost:5173',
    'http://localhost:5174',
    'http://localhost',
    'http://formbuilder.local',
    'http://127.0.0.1:5173',
];

$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
if (in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

fb_require_super_admin();

try {
    $stmt = $pdo->query("
        SELECT
            u.id,
            u.username,
            u.role,
            u.created_at,
            COUNT(f.id) AS form_count
        FROM users u
        LEFT JOIN forms f ON f.created_by = u.id
        GROUP BY u.id, u.username, u.role, u.created_at
        ORDER BY u.created_at ASC
    ");

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'users' => $users]);

} catch (Exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to retrieve users']);
}
?>
