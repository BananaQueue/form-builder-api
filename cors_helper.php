<?php
function fb_allowed_cors_origins(): array
{
    $configured = getenv('FB_ALLOWED_ORIGINS') ?: '';
    if (trim($configured) !== '') {
        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }

    return [
        'http://localhost:5173',
        'http://localhost:5174',
        'http://localhost',
        'http://formbuilder.local',
        'http://127.0.0.1:5173',
    ];
}

function fb_apply_cors(string $methods, string $headers = 'Content-Type', ?string $contentType = 'application/json'): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, fb_allowed_cors_origins(), true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: ' . $methods);
    header('Access-Control-Allow-Headers: ' . $headers);

    if ($contentType !== null) {
        header('Content-Type: ' . $contentType);
    }
}

function fb_exit_on_options(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(200);
        exit();
    }
}
?>