<?php
// Database configuration. Environment variables allow production deployments
// to keep credentials out of source while preserving the current local defaults.
$host = getenv('FB_DB_HOST') ?: 'localhost';
$dbname = getenv('FB_DB_NAME') ?: 'form_builder';
$username = getenv('FB_DB_USER') ?: 'root';
$password = getenv('FB_DB_PASS') ?: '';

// Create connection
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    
    // Set PDO to throw exceptions on error
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Set character set to UTF-8
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Optional: Uncomment to test connection
    // echo "Database connected successfully";
    
} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    die("Database connection failed");
}
?>
