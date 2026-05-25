<?php
function fb_require_auth(): int
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_samesite', 'None');
        ini_set('session.cookie_secure', '0');
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
?>
