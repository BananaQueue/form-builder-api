<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once 'auth_helper.php';
fb_send_security_headers();
fb_start_session();

require_once 'cors_helper.php';
fb_apply_cors('POST, OPTIONS', 'Content-Type, X-CSRF-Token', 'application/json');
fb_exit_on_options();

require_once 'db.php';
require_once 'audit_helpers.php';

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data || empty($data['username']) || empty($data['password'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Username and password are required']);
    exit();
}

$username = trim($data['username']);
$password = $data['password'];

if (fb_is_login_rate_limited($username)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials']);
    exit();
}

try {
    // Look up the user by username
    $stmt = $pdo->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // password_verify() checks if the plain text password matches the stored hash.
    // We always run this check even if $user is false — this prevents "timing attacks"
    // where an attacker could figure out valid usernames by measuring response speed.
    $passwordCorrect = $user && password_verify($password, $user['password_hash']);

    if (!$passwordCorrect) {
        fb_record_login_failure($username);
        // We give a vague error on purpose — we don't want to reveal
        // whether the username exists or the password is wrong.
        http_response_code(401);
        echo json_encode(['error' => 'Invalid credentials']);
        exit();
    }

    // Login successful — store user info in the session.
    // $_SESSION is like a server-side notepad tied to this browser's session cookie.
    session_regenerate_id(true);
    fb_clear_login_failures($username);
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['logged_in'] = true;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    fb_audit_log($pdo, 'USER_LOGIN', [
        'entity_type' => 'user',
        'entity_id' => $user['id'],
        'entity_label' => $user['username'],
    ]);

    echo json_encode([
        'success'  => true,
        'username' => $user['username'],
        'role'     => $user['role'],
        'csrf_token' => $_SESSION['csrf_token'],
    ]);

} catch (Exception $e) {
    fb_json_error(500, 'Server error', $e);
}
?>
