<?php
// Enable error reporting for debugging
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
    $questionStmt = $pdo->prepare("INSERT INTO questions (form_id, question_text, question_type, position) VALUES (?, ?, ?, ?)");
    $optionStmt = $pdo->prepare("INSERT INTO question_options (question_id, option_text, position) VALUES (?, ?, ?)");

    foreach ($data['questions'] as $index => $question) {
        // Insert question
        $questionStmt->execute([
            $formId,
            $question['text'],
            $question['type'],
            $index
        ]);

        // Get the ID of the inserted question
        $questionId = $pdo->lastInsertId();

        // Insert options if they exist
        if (isset($question['options']) && is_array($question['options'])) {
            foreach ($question['options'] as $optIndex => $option) {
                $optionStmt->execute([
                    $questionId,
                    $option,
                    $optIndex
                ]);
            }
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
