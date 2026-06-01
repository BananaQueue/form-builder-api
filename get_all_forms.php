<?php
require_once 'auth_helper.php';
require_once 'db.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

// Only super admins can access this endpoint
fb_require_super_admin();

// ── Pagination parameters ──────────────────────────────────────────────────
// We read these from the URL query string.
// intval() converts whatever the browser sends into a safe integer.
// We set sensible defaults and floors so bad values can't break the query.
$per_page   = max(1, intval($_GET['per_page'] ?? 10));
$page       = max(1, intval($_GET['page']     ?? 1));
$offset     = ($page - 1) * $per_page;

// ── Filter parameters ──────────────────────────────────────────────────────
// These are optional. If not provided they default to null or empty string
// and we simply skip that filter condition in the query.
$search      = trim($_GET['search']      ?? '');
$category_id = intval($_GET['category_id'] ?? 0);
$owner_id    = intval($_GET['owner_id']    ?? 0);

// ── Sort parameter ─────────────────────────────────────────────────────────
// We use a whitelist approach for sorting.
// Never trust user input directly in an ORDER BY clause — it's a SQL
// injection risk. Instead we map allowed sort keys to safe SQL strings.
$sort_by = $_GET['sort_by'] ?? 'created_desc';

$sort_map = [
    'created_desc' => 'f.created_at DESC',
    'created_asc'  => 'f.created_at ASC',
    'title_asc'    => 'f.title ASC',
    'title_desc'   => 'f.title DESC',
    'owner_asc'    => 'u.username ASC',
    'responses_desc' => 'response_count DESC',
];

// If the requested sort key isn't in our whitelist, fall back to default.
$order_sql = $sort_map[$sort_by] ?? $sort_map['created_desc'];

// ── Build WHERE conditions dynamically ─────────────────────────────────────
// We build an array of condition strings and a parallel array of bound
// values. This keeps the query readable and prevents SQL injection.
$conditions = [];
$params     = [];

if ($search !== '') {
    // Search across title, description, and owner username
    $conditions[] = '(f.title LIKE ? OR f.description LIKE ? OR u.username LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($category_id > 0) {
    $conditions[] = 'f.category_id = ?';
    $params[] = $category_id;
}

if ($owner_id > 0) {
    $conditions[] = 'f.created_by = ?';
    $params[] = $owner_id;
}

// Combine all conditions into a single WHERE clause.
// If there are no conditions, $where_sql will be an empty string
// and the query will return all forms.
$where_sql = count($conditions) > 0
    ? 'WHERE ' . implode(' AND ', $conditions)
    : '';

try {
    // ── Count query ────────────────────────────────────────────────────────
    // We run this first to know the total number of matching forms.
    // This is what allows us to calculate total_pages on the frontend.
    // We use the same WHERE conditions so the count matches the data query.
    $count_sql = "
        SELECT COUNT(DISTINCT f.id) as total
        FROM forms f
        LEFT JOIN users u ON f.created_by = u.id
        {$where_sql}
    ";

    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total = (int) $count_stmt->fetchColumn();

    // ── Data query ─────────────────────────────────────────────────────────
    // Now fetch the actual page of forms.
    // We join users to get the owner's username and role.
    // We join responses to get the response count per form.
    // We join categories to get the category name.
    // LIMIT and OFFSET handle the pagination slice.
    $data_sql = "
        SELECT
            f.id,
            f.form_code,
            f.title,
            f.description,
            f.created_at,
            f.category_id,
            c.name         AS category_name,
            u.id           AS owner_id,
            u.username     AS owner_username,
            u.role         AS owner_role,
            COUNT(DISTINCT q.id) AS question_count,
            COUNT(DISTINCT r.id) AS response_count
        FROM forms f
        LEFT JOIN users      u ON f.created_by  = u.id
        LEFT JOIN categories c ON f.category_id = c.id
        LEFT JOIN questions  q ON f.id          = q.form_id
                               AND q.question_type != 'section'
        LEFT JOIN responses  r ON f.id          = r.form_id
        {$where_sql}
        GROUP BY
            f.id, f.form_code, f.title, f.description,
            f.created_at, f.category_id,
            c.name, u.id, u.username, u.role
        ORDER BY {$order_sql}
        LIMIT {$per_page} OFFSET {$offset}
    ";

    // Append pagination values to the params array.
    // These come AFTER the WHERE params because LIMIT and OFFSET
    // appear at the end of the query.
    $data_params   = $params;

    $data_stmt = $pdo->prepare($data_sql);
    $data_stmt->execute($data_params);
    $forms = $data_stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Summary metrics ────────────────────────────────────────────────────
    // These are global counts, not filtered by the current search.
    // They always reflect the state of the entire platform.
    $metrics_stmt = $pdo->query("
        SELECT
            (SELECT COUNT(*) FROM forms)     AS total_forms,
            (SELECT COUNT(*) FROM users)     AS total_users,
            (SELECT COUNT(*) FROM responses) AS total_responses
    ");
    $metrics = $metrics_stmt->fetch(PDO::FETCH_ASSOC);

    // ── Build response ─────────────────────────────────────────────────────
    echo json_encode([
        'success' => true,
        'forms'   => $forms,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $per_page,
            'total_pages' => (int) ceil($total / $per_page),
        ],
        'metrics' => [
            'total_forms'     => (int) $metrics['total_forms'],
            'total_users'     => (int) $metrics['total_users'],
            'total_responses' => (int) $metrics['total_responses'],
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'Failed to retrieve forms',
        'message' => $e->getMessage(),
    ]);

    error_log($e->getMessage());
}
?>