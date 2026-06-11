<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ── Authentication ─────────────────────────────────────────────────────────
// We require a valid login session before returning any response data.
// fb_require_auth() is defined in auth_helper.php. It calls session_start()
// internally, checks $_SESSION['logged_in'], and exits with HTTP 401 if the
// check fails. This means unauthenticated callers never reach the database
// queries below.
//
// WHY THIS MATTERS HERE:
// Responses contain personal information submitted by members of the public
// (names, emails, answers). Only administrators should be able to read them.
// Without this check, anyone who knew the URL could read all submissions.
require_once 'auth_helper.php';
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
header('Content-Type: application/json');

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
    echo json_encode(['error' => 'Form ID is required']);
    exit();
}

// ── Ownership check ────────────────────────────────────────────────────────
// A regular user should only be able to view responses for forms they own.
// A super admin can view responses for any form.
// We read the current user's ID and role from the session (set during login).
//
// WHY CHECK OWNERSHIP?
// Without this, a logged-in regular user could read another user's form
// responses just by changing the form_id in the URL. Session data lives
// server-side and cannot be tampered with by the browser, making it a
// safe source of truth for who is making the request.
$currentUserId = (int) $_SESSION['user_id'];
$isSuperAdmin  = isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin';

try {
    // Get form details — we also verify the form exists in this step
    $stmt = $pdo->prepare("SELECT id, title, created_by FROM forms WHERE id = ?");
    $stmt->execute([$form_id]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$form) {
        http_response_code(404);
        echo json_encode(['error' => 'Form not found']);
        exit();
    }

    // If the caller is not a super admin, confirm they own this form.
    // (int) cast ensures we compare integers to integers, not mixed types.
    if (!$isSuperAdmin && (int) $form['created_by'] !== $currentUserId) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to view responses for this form']);
        exit();
    }

    // Get all responses for this form
    $stmt = $pdo->prepare("
        SELECT 
            id,
            submitted_at
        FROM responses
        WHERE form_id = ?
        ORDER BY submitted_at DESC
    ");
    $stmt->execute([$form_id]);
    $responses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // For each response, get the answer count
    foreach ($responses as &$response) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as answer_count
            FROM answers
            WHERE response_id = ?
        ");
        $stmt->execute([$response['id']]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        $response['answer_count'] = $count['answer_count'];
    }

    // Return success with responses
    echo json_encode([
        'success'         => true,
        'form'            => [
            'id'    => $form['id'],
            'title' => $form['title'],
        ],
        'responses'       => $responses,
        'total_responses' => count($responses)
    ]);

} catch (Exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error'   => 'Failed to retrieve responses'
    ]);
}
?>
