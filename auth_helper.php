<?php
function fb_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    return (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function fb_start_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $secure = fb_is_https();

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_secure', $secure ? '1' : '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    session_start();
}

function fb_send_security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function fb_json_error(int $statusCode, string $message, ?Throwable $exception = null): void
{
    if ($exception) {
        error_log($exception->getMessage());
    }

    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit();
}

function fb_client_ip(): string
{
    $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwardedFor !== '') {
        $first = trim(explode(',', $forwardedFor)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function fb_rate_limit_dir(): string
{
    $dir = __DIR__ . '/rate_limits';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }

    return $dir;
}

function fb_rate_limit_file(string $scope, string $key): string
{
    $safeScope = preg_replace('/[^a-z0-9_-]/i', '_', $scope) ?: 'default';
    $hash = hash('sha256', $safeScope . '|' . $key);

    return fb_rate_limit_dir() . '/' . $safeScope . '_' . $hash . '.json';
}

function fb_is_rate_limited(string $scope, string $key, int $maxAttempts, int $windowSeconds): bool
{
    $file = fb_rate_limit_file($scope, $key);
    if (!is_file($file)) {
        return false;
    }

    $state = json_decode((string) @file_get_contents($file), true);
    if (!is_array($state)) {
        return false;
    }

    $now = time();
    $windowStarted = (int) ($state['window_started'] ?? 0);
    if ($windowStarted < ($now - $windowSeconds)) {
        return false;
    }

    return ((int) ($state['attempts'] ?? 0)) >= $maxAttempts;
}

function fb_record_rate_limit_attempt(string $scope, string $key, int $windowSeconds): void
{
    $file = fb_rate_limit_file($scope, $key);
    $now = time();

    $state = json_decode((string) @file_get_contents($file), true);
    if (!is_array($state) || (int) ($state['window_started'] ?? 0) < ($now - $windowSeconds)) {
        $state = ['window_started' => $now, 'attempts' => 0];
    }

    $state['attempts'] = ((int) ($state['attempts'] ?? 0)) + 1;
    @file_put_contents($file, json_encode($state), LOCK_EX);
}

function fb_login_rate_limit_file(string $username): string
{
    $key = hash('sha256', strtolower(trim($username)) . '|' . fb_client_ip());
    return fb_rate_limit_dir() . '/' . $key . '.json';
}

function fb_is_login_rate_limited(string $username): bool
{
    $file = fb_login_rate_limit_file($username);
    if (!is_file($file)) {
        return false;
    }

    $state = json_decode((string) @file_get_contents($file), true);
    if (!is_array($state)) {
        return false;
    }

    $lockedUntil = (int) ($state['locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        return true;
    }

    return false;
}

function fb_record_login_failure(string $username): void
{
    $file = fb_login_rate_limit_file($username);
    $now = time();
    $windowSeconds = 15 * 60;
    $maxFailures = 8;
    $lockSeconds = 5 * 60;

    $state = json_decode((string) @file_get_contents($file), true);
    if (!is_array($state) || (int) ($state['window_started'] ?? 0) < ($now - $windowSeconds)) {
        $state = ['window_started' => $now, 'failures' => 0, 'locked_until' => 0];
    }

    $state['failures'] = ((int) ($state['failures'] ?? 0)) + 1;
    if ($state['failures'] >= $maxFailures) {
        $state['locked_until'] = $now + $lockSeconds;
    }

    @file_put_contents($file, json_encode($state), LOCK_EX);
}

function fb_clear_login_failures(string $username): void
{
    $file = fb_login_rate_limit_file($username);
    if (is_file($file)) {
        @unlink($file);
    }
}

function fb_get_csrf_token(): string
{
    fb_start_session();

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function fb_require_csrf(): void
{
    if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    fb_start_session();

    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';

    if (!is_string($provided) || !is_string($expected) || $expected === '' || !hash_equals($expected, $provided)) {
        fb_json_error(403, 'Invalid security token');
    }
}

function fb_require_auth(): int
{
    fb_start_session();

    if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Authentication required']);
        exit();
    }

    return (int) $_SESSION['user_id'];
}

function fb_require_super_admin(): int
{
    $userId = fb_require_auth();

    if (empty($_SESSION['role']) || $_SESSION['role'] !== 'super_admin') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Super admin access required']);
        exit();
    }

    return $userId;
}

function fb_is_super_admin_session(): bool
{
    fb_start_session();
    return !empty($_SESSION['role']) && $_SESSION['role'] === 'super_admin';
}

function fb_min_password_length(): int
{
    $configured = (int) (getenv('FB_MIN_PASSWORD_LENGTH') ?: 12);
    return max(12, $configured);
}

function fb_password_policy_error(string $password): ?string
{
    $minLength = fb_min_password_length();
    if (strlen($password) < $minLength) {
        return "Password must be at least {$minLength} characters";
    }

    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include at least one uppercase letter';
    }

    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must include at least one lowercase letter';
    }

    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must include at least one number';
    }

    return null;
}
?>
