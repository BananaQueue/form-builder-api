<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ── Authentication ─────────────────────────────────────────────────────────
// CSV export contains every answer to every question in a form — this is
// the most sensitive read operation in the entire application. It must
// be behind authentication.
require_once 'auth_helper.php';
require_once 'audit_helpers.php';
fb_send_security_headers();
fb_require_auth();

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

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database connection
require_once 'db.php';

// Get form_id from URL parameter
$form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;

if (!$form_id) {
    http_response_code(400);
    // Note: we send JSON here even though the normal response is CSV,
    // because an error before we set Content-Type to CSV should still
    // be machine-readable.
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Form ID is required']);
    exit();
}

$currentUserId = (int) $_SESSION['user_id'];
$isSuperAdmin  = isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin';

try {
    // Verify the form exists and check ownership
    $stmt = $pdo->prepare("SELECT id, title, created_by FROM forms WHERE id = ?");
    $stmt->execute([$form_id]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$form) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Form not found']);
        exit();
    }

    if (!$isSuperAdmin && (int) $form['created_by'] !== $currentUserId) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'You do not have permission to export responses for this form']);
        exit();
    }

    fb_audit_log($pdo, 'RESPONSES_EXPORTED', [
        'entity_type' => 'form',
        'entity_id' => (int) $form['id'],
        'entity_label' => $form['title'],
        'metadata' => [
            'owner_user_id' => isset($form['created_by']) ? (int) $form['created_by'] : null,
        ],
    ]);

    // Get all questions for this form (to build CSV headers)
    $stmt = $pdo->prepare("
        SELECT id, question_text, position
        FROM questions
        WHERE form_id = ?
        ORDER BY position ASC
    ");
    $stmt->execute([$form_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all responses
    $stmt = $pdo->prepare("
        SELECT id, submitted_at
        FROM responses
        WHERE form_id = ?
        ORDER BY submitted_at DESC
    ");
    $stmt->execute([$form_id]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $answersByResponseAndQuestion = [];
    if (count($responses) > 0 && count($questions) > 0) {
        $responseIds = array_column($responses, 'id');
        $questionIds = array_column($questions, 'id');
        $responsePlaceholders = implode(',', array_fill(0, count($responseIds), '?'));
        $questionPlaceholders = implode(',', array_fill(0, count($questionIds), '?'));

        $answerStmt = $pdo->prepare("
            SELECT response_id, question_id, answer_text
            FROM answers
            WHERE response_id IN ({$responsePlaceholders})
              AND question_id IN ({$questionPlaceholders})
        ");
        $answerStmt->execute(array_merge($responseIds, $questionIds));

        foreach ($answerStmt->fetchAll(PDO::FETCH_ASSOC) as $answerRow) {
            $answersByResponseAndQuestion[(int) $answerRow['response_id']][(int) $answerRow['question_id']] =
                $answerRow['answer_text'];
        }
    }

    // Set headers for CSV download.
    // Content-Disposition: attachment tells the browser to download the file
    // rather than try to display it. The filename is built from the form title
    // and today's date so exported files are easy to identify later.
    header('Content-Type: text/csv; charset=utf-8');
    $safeTitle = preg_replace('/[^A-Za-z0-9_-]+/', '_', $form['title']);
    $safeTitle = trim($safeTitle, '_') ?: 'form';
    header('Content-Disposition: attachment; filename="' . $safeTitle . '_responses_' . date('Y-m-d') . '.csv"');

    // Create output stream
    $output = fopen('php://output', 'w');

    // Write CSV header row
    $headers = ['Submitted At'];
    foreach ($questions as $question) {
        $headers[] = $question['question_text'];
    }
    fputcsv($output, $headers);

    // Write data rows
    foreach ($responses as $response) {
        $row = [$response['submitted_at']];

        foreach ($questions as $question) {
            $row[] = $answersByResponseAndQuestion[(int) $response['id']][(int) $question['id']] ?? '';
        }

        fputcsv($output, $row);
    }

    fclose($output);
    exit();

} catch (Exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error'   => 'Failed to export responses'
    ]);
}
?>
