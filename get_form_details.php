<?php
// Enable error reporting
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

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database connection
require_once 'db.php';

// Get form_id from URL parameter
$form_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$form_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Form ID is required']);
    exit();
}

try {
    // ── Column existence checks ────────────────────────────────────────────
    // We check which optional columns exist before building our SELECT.
    // This makes the script safe to run even if a migration hasn't been
    // applied yet — it just falls back to NULL for missing columns.

    $stmt = $pdo->query("SHOW COLUMNS FROM forms LIKE 'form_code'");
    $formCodeColumnExists = (bool) $stmt->fetch(PDO::FETCH_ASSOC);

    // NEW: Check if the privacy_notice column exists.
    // Same pattern as the form_code check directly above.
    $stmt = $pdo->query("SHOW COLUMNS FROM forms LIKE 'privacy_notice'");
    $privacyNoticeColumnExists = (bool) $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SHOW COLUMNS FROM questions");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $questionColumns = [];
    foreach ($columns as $column) {
        $questionColumns[$column['Field']] = true;
    }

    // ── Build the SELECT for the forms table ───────────────────────────────
    // If form_code exists, select it. Otherwise select NULL AS form_code
    // so the response always has that key (just with a null value).
    $formCodeSelect = $formCodeColumnExists
        ? "f.form_code,"
        : "NULL AS form_code,";

    // NEW: Same pattern for privacy_notice.
    // If the column exists → select the real value.
    // If not → return NULL so the frontend always gets a consistent shape.
    $privacyNoticeSelect = $privacyNoticeColumnExists
        ? "f.privacy_notice,"
        : "NULL AS privacy_notice,";

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
    $questionSelectSql = implode(",\n        ", $questionSelectColumns);

    // ── Fetch the form row ─────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            f.id,
            {$formCodeSelect}
            {$privacyNoticeSelect}
            f.title,
            f.description,
            f.category_id,
            c.name as category_name,
            f.created_at
        FROM forms f
        LEFT JOIN categories c ON f.category_id = c.id
        WHERE f.id = ?
    ");
    $stmt->execute([$form_id]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$form) {
        http_response_code(404);
        echo json_encode(['error' => 'Form not found']);
        exit();
    }

    // ── Fetch questions for this form ──────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT
            {$questionSelectSql}
        FROM questions
        WHERE form_id = ?
        ORDER BY position ASC
    ");
    $stmt->execute([$form_id]);
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
        'error'   => 'Failed to retrieve form details',
        'message' => $e->getMessage()
    ]);
}