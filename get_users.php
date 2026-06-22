<?php
require_once 'auth_helper.php';
require_once 'db.php';

fb_send_security_headers();

require_once 'cors_helper.php';
fb_apply_cors('GET, OPTIONS', 'Content-Type', 'application/json');
fb_exit_on_options();

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
