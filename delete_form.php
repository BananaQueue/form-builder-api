<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'db.php';
require_once 'auth_helper.php';
require_once 'notification_helpers.php';
require_once 'audit_helpers.php';

fb_send_security_headers();

$allowed_origins = [
    'http://localhost:5173',
    'http://localhost:5174',
    'http://localhost',
    'http://formbuilder.local',
    'http://127.0.0.1:5173',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$currentUserId = fb_require_auth();
fb_require_csrf();
$isSuperAdmin = fb_is_super_admin_session();
$adminUsername = $_SESSION['username'] ?? null;

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['form_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Form ID is required']);
    exit();
}

$formId = (int) $data['form_id'];
$deletionReason = trim((string) ($data['deletion_reason'] ?? ''));

try {
    $stmt = $pdo->prepare("SELECT id, title, created_by FROM forms WHERE id = ?");
    $stmt->execute([$formId]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$form) {
        http_response_code(404);
        echo json_encode(['error' => 'Form not found']);
        exit();
    }

    $formOwnerId = (int) ($form['created_by'] ?? 0);
    $isOtherUsersForm = $formOwnerId > 0 && $formOwnerId !== $currentUserId;

    if (!$isSuperAdmin && $isOtherUsersForm) {
        http_response_code(403);
        echo json_encode(['error' => 'You can only delete your own forms']);
        exit();
    }

    if ($isSuperAdmin && $deletionReason === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Deletion reason is required']);
        exit();
    }

    $pdo->beginTransaction();

    $deleteStmt = $pdo->prepare("DELETE FROM forms WHERE id = ?");
    $deleteStmt->execute([$formId]);

    fb_audit_log($pdo, 'FORM_DELETED', [
        'entity_type' => 'form',
        'entity_id' => $formId,
        'entity_label' => $form['title'],
        'metadata' => [
            'owner_user_id' => $formOwnerId,
            'deletion_reason' => $deletionReason,
            'super_admin_action' => $isSuperAdmin,
        ],
    ]);

    if (
        $isSuperAdmin
        && $isOtherUsersForm
    ) {
        fb_create_form_notification($pdo, [
            'recipient_user_id' => $formOwnerId,
            'type' => 'FORM_DELETED',
            'form_id' => $formId,
            'form_title' => $form['title'],
            'deletion_reason' => $deletionReason,
            'admin_id' => $currentUserId,
            'admin_name' => $adminUsername,
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Form deleted successfully',
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to delete form',
    ]);
}
