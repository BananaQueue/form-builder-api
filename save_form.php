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
        $data['description'] ?? "\u00A0", // Use non-breaking space if description is empty
        $data['category_id'] ?? 1  // Default to 1 (General) if not provided
    ]);

    // Get the ID of the inserted form
    $formId = $pdo->lastInsertId();

    // Insert questions
    $questions = $data['questions'];
    if (!is_array($questions)) {
        $questions = [];
    }

  // First pass: Insert all questions and map temporary IDs to database IDs
$questionIdMap = []; // Maps React temp ID to database ID

$questionStmt = $pdo->prepare("
    INSERT INTO questions (
        form_id, 
        question_text, 
        question_type, 
        rating_scale, 
        number_min, 
        number_max, 
        number_step, 
        datetime_type, 
        position, 
        is_required, 
        condition_question_id, 
        condition_type, 
        condition_value
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$optionStmt = $pdo->prepare("INSERT INTO question_options (question_id, option_text, position) VALUES (?, ?, ?)");

foreach ($questions as $index => $question) {
    $questionStmt->execute([
        $formId,                                    // 1. form_id
        $question['question_text'] ?? ($question['text'] ?? ''),   // 2. question_text
        $question['question_type'] ?? ($question['type'] ?? 'text'), // 3. question_type
        $question['rating_scale'] ?? null,          // 4. rating_scale
        $question['number_min'] ?? null,            // 5. number_min
        $question['number_max'] ?? null,            // 6. number_max
        $question['number_step'] ?? null,           // 7. number_step
        $question['datetime_type'] ?? null,         // 8. datetime_type
        $index,                                     // 9. position
        $question['is_required'] ?? 1,              // 10. is_required
        null,                                       // 11. condition_question_id (updated in second pass)
        $question['condition_type'] ?? 'equals',    // 12. condition_type
        null                                        // 13. condition_value (updated in second pass)
    ]);
    
    $dbQuestionId = $pdo->lastInsertId();
    $clientTempId = $question['id'] ?? $index;
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
