<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

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

header('Access-Control-Allow-Methods: POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Content-Type: application/json');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database connection
require_once 'db.php';
require_once 'question_map_helpers.php';
require_once 'auth_helper.php';
require_once 'notification_helpers.php';
require_once 'audit_helpers.php';

fb_send_security_headers();
$currentUserId = fb_require_auth();
fb_require_csrf();
$isSuperAdmin = fb_is_super_admin_session();
$adminUsername = $_SESSION['username'] ?? null;
// Get JSON data
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validate data
if (!$data || !isset($data['form_id']) || !isset($data['title']) || !isset($data['questions'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data provided']);
    exit();
}

$formId      = $data['form_id'];
$title       = $data['title'];
$description = $data['description'] ?? "\u00A0";
$categoryId  = $data['category_id'] ?? 1;
$questions   = $data['questions'];

// NEW: Read privacy_notice from the incoming JSON.
// If React sent null (textarea was blank), this will be null.
// If React sent a string, this will be that string.
// The ?? null ensures we get null rather than an error if the key
// doesn't exist at all in the JSON.
$privacyNotice = 1;

// Read step_mode from the incoming JSON.
// Falls back to 0 (continuous form) if the key is missing entirely.
// We use 0 rather than null because the column is NOT NULL.
$stepMode = $data['step_mode'] ?? 0;

try {
    // Start transaction
    $pdo->beginTransaction();

    $ownerStmt = $pdo->prepare("SELECT id, title, created_by FROM forms WHERE id = ? FOR UPDATE");
    $ownerStmt->execute([$formId]);
    $formOwnerRow = $ownerStmt->fetch(PDO::FETCH_ASSOC);

    if (!$formOwnerRow) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['error' => 'Form not found']);
        exit();
    }

    $formOwnerId = (int) ($formOwnerRow['created_by'] ?? 0);
    if (!$isSuperAdmin && $formOwnerId !== $currentUserId) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['error' => 'You can only edit your own forms']);
        exit();
    }

    // Check which columns exist in the questions table
    $stmt = $pdo->query("SHOW COLUMNS FROM questions");
    $questionColumns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $questionColumns[$column['Field']] = true;
    }

    // Check which columns exist in the forms table.
    // We need this before building the UPDATE so we only reference
    // columns that actually exist — avoids SQL errors on older databases.
    $stmt = $pdo->query("SHOW COLUMNS FROM forms");
    $formColumns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $formColumns[$column['Field']] = true;
    }

    // ── Update the forms row ───────────────────────────────────────────────
    // We build the SET clause dynamically so the query works regardless
    // of which optional columns exist in the database. This means the
    // script works even if migrations 003 or 005 haven't been run yet.
    //
    // We always update: title, description, category_id
    // Optional: privacy_notice (migration 003), step_mode (migration 005)

    $setClauses = ["title = ?", "description = ?", "category_id = ?"];
    $updateValues = [$title, $description, $categoryId];

    if (isset($formColumns['privacy_notice'])) {
        $setClauses[] = "privacy_notice = ?";
        $updateValues[] = $privacyNotice;
    }

    if (isset($formColumns['step_mode'])) {
        $setClauses[] = "step_mode = ?";
        $updateValues[] = $stepMode;
    }

    // Append the WHERE clause value last
    $updateValues[] = $formId;

    $stmt = $pdo->prepare(
        "UPDATE forms SET " . implode(", ", $setClauses) . " WHERE id = ?"
    );
    $stmt->execute($updateValues);

    // ── Update questions in place ───────────────────────────────────────────
    // Do NOT delete all questions — that CASCADE-deletes historical answers.
    // Update existing rows, insert new ones, and only remove questions with
    // no submitted answers (soft-delete when is_active column exists).

    $stmt = $pdo->prepare("SELECT id FROM questions WHERE form_id = ?");
    $stmt->execute([$formId]);
    $existingQuestionIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $questionInsertColumns = [
        'form_id',
        'question_text',
        'question_type',
    ];
    $questionValueResolvers = [
        fn($question, $index, $formId) => $formId,
        fn($question, $index) => $question['question_text'] ?? $question['text'],
        fn($question, $index) => $question['question_type'] ?? $question['type'],
    ];

    $optionalQuestionColumns = [
        'description'           => fn($question) => $question['description'] ?? null,
        'rating_scale'          => fn($question) => $question['rating_scale'] ?? null,
        'number_min'            => fn($question) => $question['number_min'] ?? null,
        'number_max'            => fn($question) => $question['number_max'] ?? null,
        'number_step'           => fn($question) => $question['number_step'] ?? null,
        'datetime_type'         => fn($question) => $question['datetime_type'] ?? null,
        'position'              => fn($question, $index) => $question['position'] ?? $index,
        'is_required'           => fn($question) => $question['is_required'] ?? 1,
        'is_active'             => fn($question) => 1,
        'condition_question_id' => fn($question) => null,
        'condition_type'        => fn($question) => $question['condition_type'] ?? 'equals',
        'condition_value'       => fn($question) => null,
    ];

    foreach ($optionalQuestionColumns as $columnName => $resolver) {
        if (isset($questionColumns[$columnName])) {
            $questionInsertColumns[] = $columnName;
            $questionValueResolvers[] = $resolver;
        }
    }

    $questionInsertSql = sprintf(
        'INSERT INTO questions (%s) VALUES (%s)',
        implode(', ', $questionInsertColumns),
        implode(', ', array_fill(0, count($questionInsertColumns), '?'))
    );

    $updateSetClauses = [];
    foreach ($questionInsertColumns as $columnName) {
        if ($columnName === 'form_id') {
            continue;
        }
        $updateSetClauses[] = "{$columnName} = ?";
    }

    $questionUpdateSql = sprintf(
        'UPDATE questions SET %s WHERE id = ? AND form_id = ?',
        implode(', ', $updateSetClauses)
    );

    $canUpdateConditions = isset($questionColumns['condition_question_id']);
    $hasIsActiveColumn = isset($questionColumns['is_active']);

    $questionIdMap = [];
    $keptQuestionIds = [];

    $questionInsertStmt = $pdo->prepare($questionInsertSql);
    $questionUpdateStmt = $pdo->prepare($questionUpdateSql);
    $deleteOptionsStmt = $pdo->prepare("DELETE FROM question_options WHERE question_id = ?");
    $optionStmt = $pdo->prepare(
        "INSERT INTO question_options (question_id, option_text, position) VALUES (?, ?, ?)"
    );

    foreach ($questions as $index => $question) {
        $clientQuestionId = $question['id'] ?? null;
        $isExisting = fb_is_existing_db_question_id($clientQuestionId, $existingQuestionIds);

        $values = [];
        foreach ($questionValueResolvers as $resolver) {
            $values[] = $resolver($question, $index, $formId);
        }

        if ($isExisting) {
            $dbQuestionId = (int) $clientQuestionId;
            $updateValues = array_slice($values, 1);
            $updateValues[] = $dbQuestionId;
            $updateValues[] = $formId;
            $questionUpdateStmt->execute($updateValues);
        } else {
            $questionInsertStmt->execute($values);
            $dbQuestionId = (int) $pdo->lastInsertId();
        }

        $mapKey = $clientQuestionId ?? $index;
        $questionIdMap[$mapKey] = $dbQuestionId;
        $keptQuestionIds[] = $dbQuestionId;

        $deleteOptionsStmt->execute([$dbQuestionId]);
        if (isset($question['options']) && is_array($question['options'])) {
            foreach ($question['options'] as $optIndex => $option) {
                $optionStmt->execute([$dbQuestionId, $option, $optIndex]);
            }
        }
    }

    $countAnswersStmt = $pdo->prepare("SELECT COUNT(*) FROM answers WHERE question_id = ?");
    $softDeleteStmt = $hasIsActiveColumn
        ? $pdo->prepare("UPDATE questions SET is_active = 0 WHERE id = ? AND form_id = ?")
        : null;
    $hardDeleteStmt = $pdo->prepare("DELETE FROM questions WHERE id = ? AND form_id = ?");

    foreach ($existingQuestionIds as $existingQuestionId) {
        if (in_array($existingQuestionId, $keptQuestionIds, true)) {
            continue;
        }

        $countAnswersStmt->execute([$existingQuestionId]);
        $answerCount = (int) $countAnswersStmt->fetchColumn();

        if ($answerCount > 0 && $softDeleteStmt) {
            $softDeleteStmt->execute([$existingQuestionId, $formId]);
        } elseif ($answerCount === 0) {
            $hardDeleteStmt->execute([$existingQuestionId, $formId]);
        }
    }

    // Second pass: wire up conditional question references
    if ($canUpdateConditions) {
        $updateClauses = ['condition_question_id = ?'];
        $conditionUpdateResolvers = [
            fn($question, $conditionDbId) => $conditionDbId,
        ];

        if (isset($questionColumns['condition_type'])) {
            $updateClauses[] = 'condition_type = ?';
            $conditionUpdateResolvers[] = fn($question) => $question['condition_type'] ?? 'equals';
        }

        if (isset($questionColumns['condition_value'])) {
            $updateClauses[] = 'condition_value = ?';
            $conditionUpdateResolvers[] = fn($question) => $question['condition_value'] ?? null;
        }

        $updateConditionStmt = $pdo->prepare(
            'UPDATE questions SET ' . implode(', ', $updateClauses) . ' WHERE id = ?'
        );

        foreach ($questions as $index => $question) {
            $condRef = $question['condition_question_id'] ?? null;
            if ($condRef === null || $condRef === '') {
                continue;
            }
            $clientTempId  = $question['id'] ?? $index;
            $dbQuestionId  = fb_question_map_get($questionIdMap, $clientTempId);
            $conditionDbId = fb_question_map_get($questionIdMap, $condRef);

            if ($dbQuestionId && $conditionDbId) {
                $updateValues = [];
                foreach ($conditionUpdateResolvers as $resolver) {
                    $updateValues[] = $resolver($question, $conditionDbId);
                }
                $updateValues[] = $dbQuestionId;
                $updateConditionStmt->execute($updateValues);
            }
        }
    }

    // Commit transaction
    $pdo->commit();

    fb_audit_log($pdo, 'FORM_UPDATED', [
        'entity_type' => 'form',
        'entity_id' => (int) $formId,
        'entity_label' => $title,
        'metadata' => [
            'owner_user_id' => $formOwnerId,
            'question_count' => is_array($questions) ? count($questions) : 0,
            'super_admin_action' => $isSuperAdmin,
        ],
    ]);

    $recipientId = (int) ($formOwnerRow['created_by'] ?? 0);
    if (
        $isSuperAdmin
        && $recipientId > 0
        && $recipientId !== $currentUserId
    ) {
        fb_create_form_notification($pdo, [
            'recipient_user_id' => $recipientId,
            'type' => 'FORM_EDITED',
            'form_id' => (int) $formId,
            'form_title' => $title,
            'admin_id' => $currentUserId,
            'admin_name' => $adminUsername,
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Form updated successfully',
        'form_id' => $formId
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    error_log($e->getMessage());
    echo json_encode([
        'error'   => 'Failed to update form'
    ]);
}
?>
