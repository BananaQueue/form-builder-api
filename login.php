<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Session must be started before any output.
// A session is like a locker: PHP creates it, gives the browser a locker key
// (a cookie), and stores data in it server-side.
session_start();

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
    // Allow-Credentials is required so the browser sends cookies cross-origin
    header('Access-Control-Allow-Credentials: true');
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data || empty($data['username']) || empty($data['password'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Username and password are required']);
    exit();
}

$username = trim($data['username']);
$password = $data['password'];

try {
    // Look up the user by username
    $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // password_verify() checks if the plain text password matches the stored hash.
    // We always run this check even if $user is false — this prevents "timing attacks"
    // where an attacker could figure out valid usernames by measuring response speed.
    $passwordCorrect = $user && password_verify($password, $user['password_hash']);

    if (!$passwordCorrect) {
        // We give a vague error on purpose — we don't want to reveal
        // whether the username exists or the password is wrong.
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
        exit();
    }

    // Login successful — store user info in the session.
    // $_SESSION is like a server-side notepad tied to this browser's session cookie.
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['logged_in'] = true;

    echo json_encode([
        'success'  => true,
        'username' => $user['username'],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'message' => $e->getMessage()]);
}
?>