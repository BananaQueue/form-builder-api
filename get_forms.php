<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ── Session must start before we can read who is logged in ──────────────────
// session_start() tells PHP "look up the session cookie the browser sent
// and load the matching $_SESSION data into memory."
// Without this line, $_SESSION is always empty.
session_start();

require_once 'cors_helper.php';
fb_apply_cors('GET, OPTIONS', 'Content-Type', 'application/json');
fb_exit_on_options();

// ── Authentication check ────────────────────────────────────────────────────
// If there is no valid session, we refuse to return any data.
// http_response_code(401) means "Unauthorized" — you must log in first.
if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}

$currentUserId = $_SESSION['user_id'];
$isSuperAdmin  = isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin';

// Super admin can view any user's forms by passing ?user_id=X.
// Regular users always see only their own forms.
$targetUserId = $currentUserId;
if ($isSuperAdmin && isset($_GET['user_id']) && is_numeric($_GET['user_id'])) {
    $targetUserId = (int) $_GET['user_id'];
}

require_once 'db.php';
require_once 'response_helpers.php';

try {
    // ── The key change: WHERE f.created_by = ? ──────────────────────────────
    // The ? is a placeholder. We pass $currentUserId as the value.
    // PDO substitutes it safely — this prevents SQL injection.
    // Only forms belonging to the logged-in user are returned.
    $stmt = $pdo->prepare("
        SELECT 
            f.id,
            f.form_code,
            f.title,
            f.description,
            f.created_at,
            f.category_id,
            c.name as category_name,
            COUNT(DISTINCT CASE 
                WHEN q.question_type != 'section' THEN q.id 
                END) as question_count,
            " . fb_response_count_expr('r') . " as response_count
        FROM forms f
        LEFT JOIN categories c ON f.category_id = c.id
        LEFT JOIN questions q ON f.id = q.form_id
        LEFT JOIN responses r ON f.id = r.form_id
        LEFT JOIN answers a ON a.response_id = r.id
        WHERE f.created_by = ?
        GROUP BY f.id, c.name
        ORDER BY f.created_at DESC
    ");

    $stmt->execute([$targetUserId]);
    $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'forms'   => $forms,
    ]);

} catch (Exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error'   => 'Failed to retrieve forms',
    ]);
}
?>
