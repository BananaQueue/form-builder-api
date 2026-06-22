<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// ── Authentication ─────────────────────────────────────────────────────────
// Require a valid login session before returning any individual response data.
// See get_responses.php for a full explanation of why this matters.
require_once 'auth_helper.php';
fb_send_security_headers();
fb_require_auth();

// CORS headers
require_once 'cors_helper.php';
fb_apply_cors('GET, OPTIONS', 'Content-Type', 'application/json');
fb_exit_on_options();

// Include database connection
require_once 'db.php';

// Get response_id from URL parameter
$response_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$response_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Response ID is required']);
    exit();
}

$currentUserId = (int) $_SESSION['user_id'];
$isSuperAdmin  = isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin';

try {
    // Get response details.
    // We JOIN through to forms so we can check form ownership in one query
    // rather than making two separate database round trips.
    //
    // The JOIN chain is:
    //   responses → forms (to get title and owner)
    // This gives us everything we need in one go.
    $stmt = $pdo->prepare("
        SELECT 
            r.id,
            r.form_id,
            r.submitted_at,
            f.title  AS form_title,
            f.created_by AS form_owner_id
        FROM responses r
        JOIN forms f ON r.form_id = f.id
        WHERE r.id = ?
    ");
    $stmt->execute([$response_id]);
    $response = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$response) {
        http_response_code(404);
        echo json_encode(['error' => 'Response not found']);
        exit();
    }

    // Ownership check: regular users can only see responses for their own forms.
    if (!$isSuperAdmin && (int) $response['form_owner_id'] !== $currentUserId) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to view this response']);
        exit();
    }

    // Get all answers for this response with question details
    $stmt = $pdo->prepare("
        SELECT 
            a.id,
            a.question_id,
            a.answer_text,
            q.question_text,
            q.question_type
        FROM answers a
        JOIN questions q ON a.question_id = q.id
        WHERE a.response_id = ?
        ORDER BY q.position ASC
    ");
    $stmt->execute([$response_id]);
    $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Remove the internal form_owner_id field before sending to the client.
    // The frontend doesn't need it, and leaking internal IDs is a minor
    // information disclosure we can easily avoid.
    unset($response['form_owner_id']);

    $response['answers'] = $answers;

    echo json_encode([
        'success'  => true,
        'response' => $response
    ]);

} catch (Exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error'   => 'Failed to retrieve response details'
    ]);
}
?>
