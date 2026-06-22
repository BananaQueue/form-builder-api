<?php
require_once 'auth_helper.php';
require_once 'db.php';
require_once 'audit_helpers.php';

fb_send_security_headers();

require_once 'cors_helper.php';
fb_apply_cors('POST, OPTIONS', 'Content-Type, X-CSRF-Token', 'application/json');
fb_exit_on_options();

$currentUserId = fb_require_super_admin();
fb_require_csrf();

$data   = json_decode(file_get_contents('php://input'), true);
$userId = isset($data['user_id']) ? (int) $data['user_id'] : 0;

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid user ID']);
    exit();
}

if ($userId === $currentUserId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'You cannot delete your own account']);
    exit();
}

try {
    $pdo->beginTransaction();

    $lookup = $pdo->prepare("SELECT username, role FROM users WHERE id = ? FOR UPDATE");
    $lookup->execute([$userId]);
    $targetUser = $lookup->fetch(PDO::FETCH_ASSOC);

    if (!$targetUser) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit();
    }

    if ($targetUser['role'] === 'super_admin') {
        $superAdminLock = $pdo->query("SELECT id FROM users WHERE role = 'super_admin' FOR UPDATE");
        $superAdminIds = array_map('intval', $superAdminLock->fetchAll(PDO::FETCH_COLUMN));

        if (count($superAdminIds) <= 1) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error' => 'Cannot delete the last Super Admin account',
            ]);
            exit();
        }
    }

    $formsStmt = $pdo->prepare("SELECT COUNT(*) FROM forms WHERE created_by = ?");
    $formsStmt->execute([$userId]);
    $reassignedFormCount = (int) $formsStmt->fetchColumn();

    $detachForms = $pdo->prepare("UPDATE forms SET created_by = NULL WHERE created_by = ?");
    $detachForms->execute([$userId]);

    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$userId]);

    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found']);
        exit();
    }

    fb_audit_log($pdo, 'USER_DELETED', [
        'entity_type' => 'user',
        'entity_id' => $userId,
        'entity_label' => $targetUser['username'],
        'metadata' => [
            'role' => $targetUser['role'],
            'forms_unassigned' => $reassignedFormCount,
        ],
    ]);

    $pdo->commit();

    // Their forms remain in the database with created_by = NULL.
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to delete user']);
}
?>
