<?php
// Error reporting: log errors, but don't output warnings/notices as HTML (breaks JSON responses)
error_reporting(E_ALL);
ini_set('display_errors', 1);


// Allow requests from React app (CORS)
// Define allowed origins (whitelist)
$allowed_origins = [
    'http://localhost:5173',           // React dev server (Vite default)
    'http://localhost:5174',           // In case you run multiple React apps
    'http://localhost',                // For test.html
    'http://formbuilder.local',        // Your custom domain
    'http://127.0.0.1:5173',          // Alternative localhost
];

// Get the origin of the incoming request
$origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';

// Check if the origin is in our whitelist
if (in_array($origin, $allowed_origins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
} else {
    // If not in whitelist, don't set the header (request will be blocked)
    error_log("Blocked CORS request from: " . $origin);
}

header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Include database connection
require_once 'db.php';
require_once 'question_map_helpers.php';
require_once 'generate_form_code.php';

// Get JSON data from request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validate data
if (!$data || !isset($data['title']) || !isset($data['questions'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid data provided']);
    exit();
}

try {
    // Start transaction
    $pdo->beginTransaction();

    // Check which columns exist in the forms table.
    // We do this so the script works even on older databases that
    // haven't had every migration run yet.
    $stmt = $pdo->query("SHOW COLUMNS FROM forms");
    $formColumns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $formColumns[$column['Field']] = true;
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM questions");
    $questionColumns = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $questionColumns[$column['Field']] = true;
    }

    // ── Build the INSERT for the forms table dynamically ──────────────────
    // We always insert title, description, and category_id.
    // Then we check for optional columns (form_code, privacy_notice) and
    // only include them if they actually exist in the database.
    // This prevents SQL errors on databases missing a column.

    $formInsertColumns = ['title', 'description', 'category_id'];
    $formInsertValues = [
        $data['title'],
        $data['description'] ?? "\u00A0", // Non-breaking space if empty
        $data['category_id'] ?? 1,
    ];

    // Include form_code if that column exists (added in migration 002)
    $formCode = null;
    if (isset($formColumns['form_code'])) {
        $formInsertColumns[] = 'form_code';
        $formCode = generateFormCodeWithSlug($pdo, $data['title']);
        $formInsertValues[] = $formCode;
    }

    // NEW: Include privacy_notice if that column exists (added in migration 003).
    // We read it from the incoming JSON. If the React app sent null or didn't
    // send it at all, we store NULL in the database — which means "no modal".
    if (isset($formColumns['privacy_notice'])) {
        $formInsertColumns[] = 'privacy_notice';
        $formInsertValues[] = $data['privacy_notice'] ?? null;
    }

    // Include step_mode if that column exists (added in migration 005).
    // 0 = continuous form (default), 1 = multi-step wizard.
    // We fall back to 0 rather than null because the column is NOT NULL.
    if (isset($formColumns['step_mode'])) {
        $formInsertColumns[] = 'step_mode';
        $formInsertValues[] = $data['step_mode'] ?? 0;
    }

    // Build and run the INSERT statement
    $stmt = $pdo->prepare(sprintf(
        'INSERT INTO forms (%s) VALUES (%s)',
        implode(', ', $formInsertColumns),
        implode(', ', array_fill(0, count($formInsertColumns), '?'))
    ));
    $stmt->execute($formInsertValues);

    // Get the ID of the newly inserted form row
    $formId = $pdo->lastInsertId();

    // ── Insert questions ───────────────────────────────────────────────────
    $questions = $data['questions'];
    if (!is_array($questions)) {
        $questions = [];
    }

    // First pass: Insert all questions and map temporary IDs to database IDs.
    // React assigns temporary IDs like "q_1234567890" to new questions.
    // After inserting each question, MySQL gives it a real integer ID.
    // We store the mapping so we can link conditional questions correctly.
    $questionIdMap = [];

    $questionInsertColumns = [
        'form_id',
        'question_text',
        'question_type',
    ];
    $questionValueResolvers = [
        fn($question, $index, $formId) => $formId,
        fn($question, $index) => $question['question_text'] ?? ($question['text'] ?? ''),
        fn($question, $index) => $question['question_type'] ?? ($question['type'] ?? 'text'),
    ];

    $optionalQuestionColumns = [
        'rating_scale'          => fn($question) => $question['rating_scale'] ?? null,
        'number_min'            => fn($question) => $question['number_min'] ?? null,
        'number_max'            => fn($question) => $question['number_max'] ?? null,
        'number_step'           => fn($question) => $question['number_step'] ?? null,
        'datetime_type'         => fn($question) => $question['datetime_type'] ?? null,
        'position'              => fn($question, $index) => $index,
        'is_required'           => fn($question) => $question['is_required'] ?? 1,
        'condition_question_id' => fn($question) => null, // Set in second pass
        'condition_type'        => fn($question) => $question['condition_type'] ?? 'equals',
        'condition_value'       => fn($question) => null, // Set in second pass
    ];

    foreach ($optionalQuestionColumns as $columnName => $resolver) {
        if (isset($questionColumns[$columnName])) {
            $questionInsertColumns[] = $columnName;
            $questionValueResolvers[] = $resolver;
        }
    }

    $questionStmt = $pdo->prepare(sprintf(
        'INSERT INTO questions (%s) VALUES (%s)',
        implode(', ', $questionInsertColumns),
        implode(', ', array_fill(0, count($questionInsertColumns), '?'))
    ));

    $optionStmt = $pdo->prepare(
        "INSERT INTO question_options (question_id, option_text, position) VALUES (?, ?, ?)"
    );

    foreach ($questions as $index => $question) {
        $values = [];
        foreach ($questionValueResolvers as $resolver) {
            $values[] = $resolver($question, $index, $formId);
        }
        $questionStmt->execute($values);

        $dbQuestionId = $pdo->lastInsertId();
        $clientTempId = $question['id'] ?? $index;
        $questionIdMap[$clientTempId] = $dbQuestionId;

        // Insert this question's options (for checkbox/multiple_choice/rating)
        if (isset($question['options']) && is_array($question['options'])) {
            foreach ($question['options'] as $optIndex => $option) {
                $optionStmt->execute([$dbQuestionId, $option, $optIndex]);
            }
        }
    }

    // Second pass: Update conditional logic references.
    // Now that all questions have real DB IDs, we can wire up the
    // condition_question_id foreign keys correctly.
    if (isset($questionColumns['condition_question_id'])) {
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
            $clientTempId = $question['id'] ?? $index;
            $dbQuestionId = fb_question_map_get($questionIdMap, $clientTempId);
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

    // Commit — everything succeeded, write it all to the database
    $pdo->commit();

    echo json_encode([
        'success'   => true,
        'message'   => 'Form saved successfully',
        'form_id'   => $formId,
        'form_code' => $formCode
    ]);

} catch (Exception $e) {
    // Something went wrong — roll back so we don't leave partial data
    $pdo->rollBack();

    http_response_code(500);
    echo json_encode([
        'error'   => 'Failed to save form',
        'message' => $e->getMessage()
    ]);
}