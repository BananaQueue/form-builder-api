<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

try {
    // Get all forms with question count
    $stmt = $pdo->query("
    SELECT 
        f.id,
        f.title,
        f.description,
        f.created_at,
        f.category_id,
        c.name as category_name,
        COUNT(q.id) as question_count
    FROM forms f
    LEFT JOIN categories c ON f.category_id = c.id
    LEFT JOIN questions q ON f.id = q.form_id
    GROUP BY f.id, c.name
    ORDER BY f.created_at DESC
    ");
    
    $forms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return success with forms data
    echo json_encode([
        'success' => true,
        'forms' => $forms
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to retrieve forms',
        'message' => $e->getMessage()
    ]);
}
?>