<?php
// Error reporting: log errors, but don't output warnings/notices as HTML (breaks JSON responses)
error_reporting(E_ALL);
ini_set('display_errors', 0);

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
    // Optionally log this for security monitoring
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

    // Insert form with category
    $stmt = $pdo->prepare("INSERT INTO forms (title, description, category_id) VALUES (?, ?, ?)");
    $stmt->execute([
        $data['title'],
        $data['description'] ?? '',
        $data['category_id'] ?? 1  // Default to 1 (General) if not provided
    ]);

    // Get the ID of the inserted form
    $formId = $pdo->lastInsertId();

    // Insert questions
    $questions = $data['questions'];
    if (!is_array($questions)) {
        $questions = [];
    }

    // First pass: Insert all questions and map temporary IDs to database IDs (if provided)
    $questionIdMap = []; // Maps client temp ID -> database ID

    $questionStmt = $pdo->prepare("INSERT INTO questions (form_id, question_text, question_type, rating_scale, position, is_required, condition_question_id, condition_type, condition_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $optionStmt = $pdo->prepare("INSERT INTO question_options (question_id, option_text, position) VALUES (?, ?, ?)");

    foreach ($questions as $index => $question) {
        // Support both old client keys (text/type) and new keys (question_text/question_type)
        $questionText = $question['question_text'] ?? ($question['text'] ?? '');
        $questionType = $question['question_type'] ?? ($question['type'] ?? 'text');

        // If client didn't send an ID, fall back to index-based mapping
        $clientTempId = $question['id'] ?? $index;

        $questionStmt->execute([
            $formId,
            $questionText,
            $questionType,
            $question['rating_scale'] ?? null,
            $question['position'] ?? $index,
            $question['is_required'] ?? 1,
            null, // Will update in second pass
            $question['condition_type'] ?? 'equals',
            null  // Will update in second pass
        ]);

        $dbQuestionId = $pdo->lastInsertId();
        $questionIdMap[$clientTempId] = $dbQuestionId;

        // Insert options
        if (isset($question['options']) && is_array($question['options'])) {
            foreach ($question['options'] as $optIndex => $option) {
                $optionStmt->execute([
                    $dbQuestionId,
                    $option,
                    $optIndex
                ]);
            }
        }
    }

    // Second pass: Update conditional logic references
    $updateConditionStmt = $pdo->prepare("UPDATE questions SET condition_question_id = ?, condition_type = ?, condition_value = ? WHERE id = ?");

    foreach ($questions as $index => $question) {
        $condRef = $question['condition_question_id'] ?? null;
        if ($condRef === null || $condRef === '') {
            continue;
        }
        $clientTempId = $question['id'] ?? $index;
        $dbQuestionId = fb_question_map_get($questionIdMap, $clientTempId);
        $conditionDbId = fb_question_map_get($questionIdMap, $condRef);

        if ($dbQuestionId && $conditionDbId) {
            $updateConditionStmt->execute([
                $conditionDbId,
                $question['condition_type'] ?? 'equals',
                $question['condition_value'] ?? null,
                $dbQuestionId
            ]);
        }
    }

    // Commit transaction
    $pdo->commit();

    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Form saved successfully',
        'form_id' => $formId
    ]);
} catch (Exception $e) {
    // Rollback transaction on error
    $pdo->rollBack();

    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to save form',
        'message' => $e->getMessage()
    ]);
}
