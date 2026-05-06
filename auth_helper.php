<?php
function fb_require_auth(): int
{
    if (session_status() === PHP_SESSION_NONE) {
        // These settings must be configured BEFORE session_start() is called.
        // They tell PHP how to handle the session cookie across origins.

        // SameSite=None allows the cookie to be sent on cross-origin requests.
        // Without this, the browser silently drops the cookie when React
        // (on port 5173) talks to PHP (on port 80) — they're different origins.
        ini_set('session.cookie_samesite', 'None');

        // Secure=true is normally required when SameSite=None, but since we're
        // on HTTP locally (not HTTPS), we set it to false for development.
        // On a production HTTPS server, change this to true.
        ini_set('session.cookie_secure', '0');

        // httponly=true means JavaScript can't read the cookie directly —
        // only the browser sends it automatically with requests. Safer.
        ini_set('session.cookie_httponly', '1');

        session_start();
    }

    if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Authentication required']);
        exit();
    }

    return (int) $_SESSION['user_id'];
}
?>