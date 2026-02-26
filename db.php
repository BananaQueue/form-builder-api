<?php
// Database configuration
$host = 'localhost';
$dbname = 'form_builder';
$username = 'root';
$password = '';

// Create connection
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    
    // Set PDO to throw exceptions on error
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Set character set to UTF-8
    $pdo->setAttribute(PDO::MYSQL_ATTR_INIT_COMMAND, "SET NAMES utf8");
    
    // Optional: Uncomment to test connection
    // echo "Database connected successfully";
    
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>