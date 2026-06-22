<?php
require_once 'auth_helper.php';
require_once 'audit_helpers.php';
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

function fb_normalize_update_change_text(string $change, ?int $questionNumber = null): string
{
    $normalized = preg_replace('/\s+(for|from)\s+".*"$/', '', $change);
    $normalized = preg_replace('/\s+".*"$/', '', $normalized ?? $change);
    $normalized = trim((string) $normalized);

    if (
        $questionNumber !== null
        && $questionNumber > 0
        && !preg_match('/\((Question|Section)\s+\d+\)$/', $normalized)
        && stripos($normalized, 'question') !== false
    ) {
        $normalized .= " (Question {$questionNumber})";
    }

    return $normalized === '' ? 'Updated form details' : $normalized;
}

function fb_normalize_audit_log_metadata(PDO $pdo, array $logs): array
{
    $ownerIds = [];
    foreach ($logs as $log) {
        $metadata = json_decode((string) ($log['metadata'] ?? ''), true);
        if (is_array($metadata) && isset($metadata['owner_user_id'])) {
            $ownerIds[] = (int) $metadata['owner_user_id'];
        }
    }

    $ownerNames = [];
    $ownerIds = array_values(array_unique(array_filter($ownerIds)));
    if (count($ownerIds) > 0) {
        $placeholders = implode(', ', array_fill(0, count($ownerIds), '?'));
        $stmt = $pdo->prepare("SELECT id, username FROM users WHERE id IN ({$placeholders})");
        $stmt->execute($ownerIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $user) {
            $ownerNames[(int) $user['id']] = $user['username'];
        }
    }

    foreach ($logs as &$log) {
        $metadata = json_decode((string) ($log['metadata'] ?? ''), true);
        if (!is_array($metadata)) {
            continue;
        }

        if (isset($metadata['owner_user_id']) && !isset($metadata['form_owner'])) {
            $ownerId = (int) $metadata['owner_user_id'];
            $metadata['form_owner'] = $ownerNames[$ownerId] ?? ('User #' . $ownerId);
            unset($metadata['owner_user_id']);
        }

        if (isset($metadata['super_admin_action'])) {
            unset($metadata['super_admin_action']);
        }

        if (($log['action'] ?? '') === 'FORM_UPDATED' && empty($metadata['changes'])) {
            $metadata['changes'] = ['Updated form details'];
        }

        if (($log['action'] ?? '') === 'FORM_UPDATED') {
            $questionNumber = isset($metadata['question_count']) ? (int) $metadata['question_count'] : null;
            if (isset($metadata['changes']) && is_array($metadata['changes'])) {
                $metadata['changes'] = array_values(array_map(
                    fn($change) => fb_normalize_update_change_text((string) $change, $questionNumber),
                    $metadata['changes']
                ));
            }
            unset($metadata['question_count']);
        }

        $log['metadata'] = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    unset($log);

    return $logs;
}

try {
    if (!fb_ensure_audit_logs_table($pdo)) {
        throw new RuntimeException('Audit logs table is unavailable');
    }

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
    $logs = fb_normalize_audit_log_metadata($pdo, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $actionsStmt = $pdo->query("
        SELECT DISTINCT action
        FROM audit_logs
        ORDER BY action ASC
    ");

    echo json_encode([
        'success' => true,
        'logs' => $logs,
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
