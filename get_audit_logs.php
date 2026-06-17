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
if (in_array($origin, $allowed_origins, true)) {
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

fb_require_super_admin();

try {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = min(100, max(10, (int) ($_GET['page_size'] ?? 25)));
    $offset = ($page - 1) * $pageSize;
    $action = trim((string) ($_GET['action'] ?? ''));
    $search = trim((string) ($_GET['search'] ?? ''));

    $where = [];
    $params = [];

    if ($action !== '') {
        $where[] = 'action = ?';
        $params[] = $action;
    }

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(
            actor_username LIKE ?
            OR actor_role LIKE ?
            OR entity_type LIKE ?
            OR entity_label LIKE ?
            OR action LIKE ?
            OR ip_address LIKE ?
        )';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }

    $whereSql = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

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
            ip_address,
            user_agent,
            created_at
        FROM audit_logs
        {$whereSql}
        ORDER BY created_at DESC, id DESC
        LIMIT {$pageSize} OFFSET {$offset}
    ");
    $stmt->execute($params);

    $actionsStmt = $pdo->query("
        SELECT DISTINCT action
        FROM audit_logs
        ORDER BY action ASC
    ");

    echo json_encode([
        'success' => true,
        'logs' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'actions' => $actionsStmt->fetchAll(PDO::FETCH_COLUMN),
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'total_pages' => (int) max(1, ceil($total / $pageSize)),
        ],
    ]);
} catch (Throwable $e) {
    error_log('Audit log fetch failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to retrieve audit logs']);
}
?>
