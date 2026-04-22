<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// CORS headers
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

require_once 'db.php';

// Get form_code from URL parameter
$form_code = isset($_GET['code']) ? trim($_GET['code']) : '';

if (!$form_code) {
    http_response_code(400);
    echo json_encode(['error' => 'Form code is required']);
    exit();
}

// If the caller provided `slug-uniqueCode`, try the last segment too.
// If the caller provided just `uniqueCode`, this will be the same string.
$unique_code_candidate = $form_code;
if (strpos($form_code, '-') !== false) {
    $parts = explode('-', $form_code);
    $unique_code_candidate = end($parts);
}

try {
    // ── Column existence checks ────────────────────────────────────────────

    $stmt = $pdo->query("SHOW COLUMNS FROM forms LIKE 'form_code'");
    $formCodeColumnExists = (bool) $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$formCodeColumnExists) {
        http_response_code(500);
        echo json_encode([
            'error'   => 'Failed to retrieve form',
            'message' => 'The database is missing the form_code column in forms.'
        ]);
        exit();
    }

    // NEW: Check whether privacy_notice exists in the forms table.
    // Same one-liner pattern used for form_code above.
    $stmt = $pdo->query("SHOW COLUMNS FROM forms LIKE 'privacy_notice'");
    $privacyNoticeColumnExists = (bool) $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SHOW COLUMNS FROM questions");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $questionColumns = [];
    foreach ($columns as $column) {
        $questionColumns[$column['Field']] = true;
    }

    // Build the question SELECT columns list
    $questionSelectColumns = [
        "id",
        "question_text",
        "question_type",
        isset($questionColumns['rating_scale'])          ? "rating_scale"          : "NULL AS rating_scale",
        isset($questionColumns['number_min'])            ? "number_min"            : "NULL AS number_min",
        isset($questionColumns['number_max'])            ? "number_max"            : "NULL AS number_max",
        isset($questionColumns['number_step'])           ? "number_step"           : "NULL AS number_step",
        isset($questionColumns['datetime_type'])         ? "datetime_type"         : "NULL AS datetime_type",
        "position",
        isset($questionColumns['is_required'])           ? "is_required"           : "1 AS is_required",
        isset($questionColumns['condition_question_id']) ? "condition_question_id" : "NULL AS condition_question_id",
        isset($questionColumns['condition_type'])        ? "condition_type"        : "'equals' AS condition_type",
        isset($questionColumns['condition_value'])       ? "condition_value"       : "NULL AS condition_value",
    ];
    $questionSelectSql = implode(",\n            ", $questionSelectColumns);

    // NEW: Build the privacy_notice part of the SELECT dynamically.
    // If the column exists, select the real value.
    // If not, return NULL so the JSON response shape is always consistent —
    // the frontend can always check form.privacy_notice without crashing.
    $privacyNoticeSelect = $privacyNoticeColumnExists
        ? "f.privacy_notice,"
        : "NULL AS privacy_notice,";

    // ── Build the form SELECT query ────────────────────────────────────────
    // Notice privacy_notice is now included in the SELECT list.
    $formSelectSql = "
        SELECT
            f.id,
            f.title,
            f.description,
            {$privacyNoticeSelect}
            f.category_id,
            c.name as category_name,
            f.created_at
        FROM forms f
        LEFT JOIN categories c ON f.category_id = c.id
        WHERE f.form_code = ?
    ";

    // ── Three-step lookup for the form ─────────────────────────────────────
    // 1) Exact match on whatever the URL provided
    $stmt = $pdo->prepare($formSelectSql);
    $stmt->execute([$form_code]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    // 2) Try just the unique tail segment (handles slug-code URLs)
    if (!$form && $unique_code_candidate !== $form_code) {
        $stmt->execute([$unique_code_candidate]);
        $form = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // 3) Suffix match: DB has `some-slug-$uniqueCode`
    if (!$form) {
        $stmtLike = $pdo->prepare(str_replace(
            'WHERE f.form_code = ?',
            'WHERE f.form_code LIKE ?',
            $formSelectSql
        ));
        $stmtLike->execute(['%-' . $unique_code_candidate]);
        $form = $stmtLike->fetch(PDO::FETCH_ASSOC);
    }

    if (!$form) {
        http_response_code(404);
        echo json_encode(['error' => 'Form not found']);
        exit();
    }

    // ── Fetch questions ────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            {$questionSelectSql}
        FROM questions
        WHERE form_id = ?
        ORDER BY position ASC
    ");
    $stmt->execute([$form['id']]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For each question, fetch its options
    foreach ($questions as &$question) {
        $stmt = $pdo->prepare("
            SELECT option_text, position
            FROM question_options
            WHERE question_id = ?
            ORDER BY position ASC
        ");
        $stmt->execute([$question['id']]);
        $options = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $question['options'] = array_map(function ($opt) {
            return $opt['option_text'];
        }, $options);
    }

    $form['questions'] = $questions;

    echo json_encode([
        'success' => true,
        'form'    => $form
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error'   => 'Failed to retrieve form',
        'message' => $e->getMessage()
    ]);
}
?>