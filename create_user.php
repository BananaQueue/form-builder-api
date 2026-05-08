<?php
require_once 'db.php';

// ── Change these before running ──────────────────────────────────────────────
$username = 'admin_ORD';           // The login username
$password = '0RD@dm1n';  // The plain text password (only used here to hash it)
// ─────────────────────────────────────────────────────────────────────────────

// password_hash() scrambles the password using the bcrypt algorithm.
// The result looks like: $2y$10$someVeryLongRandomString
// You can NEVER reverse this back into the original password — that's the point.
$hash = password_hash($password, PASSWORD_BCRYPT);

try {
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
    $stmt->execute([$username, $hash]);
    echo "User '{$username}' created successfully.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>