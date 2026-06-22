<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found";
    exit(1);
}

require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/db.php';

$username = trim((string) (getenv('FB_BOOTSTRAP_ADMIN_USERNAME') ?: ($argv[1] ?? '')));
$password = (string) (getenv('FB_BOOTSTRAP_ADMIN_PASSWORD') ?: ($argv[2] ?? ''));

if ($username === '' || $password === '') {
    fwrite(STDERR, "Usage: set FB_BOOTSTRAP_ADMIN_USERNAME and FB_BOOTSTRAP_ADMIN_PASSWORD, or pass username and password as CLI arguments.\n");
    exit(1);
}

$passwordError = fb_password_policy_error($password);
if ($passwordError) {
    fwrite(STDERR, $passwordError . "\n");
    exit(1);
}

try {
    $pdo->beginTransaction();

    $existingSuperAdmin = $pdo->query("SELECT id FROM users WHERE role = 'super_admin' LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
    if ($existingSuperAdmin) {
        $pdo->rollBack();
        fwrite(STDERR, "A Super Admin already exists. Bootstrap aborted.\n");
        exit(1);
    }

    $check = $pdo->prepare('SELECT id FROM users WHERE username = ? FOR UPDATE');
    $check->execute([$username]);
    if ($check->fetch()) {
        $pdo->rollBack();
        fwrite(STDERR, "Username already exists. Bootstrap aborted.\n");
        exit(1);
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO users (username, role, password_hash) VALUES (?, 'super_admin', ?)");
    $stmt->execute([$username, $hash]);

    $pdo->commit();
    echo "Super Admin created: {$username}\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log($e->getMessage());
    fwrite(STDERR, "Failed to bootstrap Super Admin.\n");
    exit(1);
}
?>