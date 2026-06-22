<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 0);

// CORS headers
require_once 'cors_helper.php';
fb_apply_cors('GET, OPTIONS', 'Content-Type', 'application/json');
fb_exit_on_options();

// Include database connection
require_once 'db.php';

try {
    // Get all categories
    $stmt = $pdo->query("SELECT id, name FROM categories ORDER BY name");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Return success with categories
    echo json_encode([
        'success' => true,
        'categories' => $categories
    ]);
    
} catch (Exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to retrieve categories'
    ]);
}
?>
