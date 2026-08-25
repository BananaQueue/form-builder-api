<?php

use Illuminate\Support\Facades\Route;

$appIndex = public_path('app/index.html');
$serveReactApp = function () use ($appIndex) {
    return is_file($appIndex)
        ? response()->file($appIndex)
        : view('welcome');
};

Route::get('/', $serveReactApp);

Route::get('/_fb_laravel_health', function () {
    return response()->json([
        'ok' => true,
        'app' => config('app.name'),
        'backend' => 'laravel',
    ]);
});

/*
|--------------------------------------------------------------------------
| Public endpoints (no session required)
|--------------------------------------------------------------------------
| Session check, auth, public form lookup + submission, and the guarded
| E2E test helpers (which enforce their own env/database/token guards).
*/
Route::get('/api/session', [\App\Http\Controllers\LegacyAuthController::class, 'checkSession']);
Route::post('/api/login', [\App\Http\Controllers\LegacyAuthController::class, 'login']);
Route::post('/api/logout', [\App\Http\Controllers\LegacyAuthController::class, 'logout']);

Route::get('/api/categories', [\App\Http\Controllers\LegacyLookupController::class, 'categories']);

Route::get('/api/public/forms/{code}', function (\Illuminate\Http\Request $request, string $code) {
    $request->query->set('code', $code);

    return app(\App\Http\Controllers\LegacyLookupController::class)->publicFormByCode($request);
});

Route::post('/api/public/forms/{id}/responses', function (\Illuminate\Http\Request $request, int $id) {
    $payload = $request->json()->all();
    $payload['form_id'] = $id;
    $request->json()->replace($payload);

    return app(\App\Http\Controllers\LegacySubmissionController::class)->submitResponse($request);
})->whereNumber('id');

Route::get('/test_database_guard.php', [\App\Http\Controllers\LegacyTestController::class, 'databaseGuard']);
Route::post('/test_reset_database.php', [\App\Http\Controllers\LegacyTestController::class, 'resetDatabase']);
Route::get('/test_audit_logs.php', [\App\Http\Controllers\LegacyTestController::class, 'auditLogs']);
Route::get('/test_last_reset_code.php', [\App\Http\Controllers\LegacyTestController::class, 'lastResetCode']);

/*
|--------------------------------------------------------------------------
| Authenticated endpoints (any logged-in user)
|--------------------------------------------------------------------------
| Owner-scoped authorization (created_by checks) still happens inside the
| controllers; this group only enforces that a session exists.
*/
Route::middleware('legacy.auth')->group(function (): void {
    Route::get('/api/notifications', [\App\Http\Controllers\LegacyNotificationController::class, 'notifications']);
    Route::get('/api/notifications/pending', [\App\Http\Controllers\LegacyNotificationController::class, 'pending']);
    Route::post('/api/notifications/{id}/acknowledge', function (\Illuminate\Http\Request $request, int $id) {
        $payload = $request->json()->all();
        $payload['notification_id'] = $id;
        $request->json()->replace($payload);

        return app(\App\Http\Controllers\LegacyNotificationController::class)->acknowledge($request);
    })->whereNumber('id');
    Route::post('/api/notifications/{id}/read', function (\Illuminate\Http\Request $request, int $id) {
        $payload = $request->json()->all();
        $payload['notification_id'] = $id;
        $request->json()->replace($payload);

        return app(\App\Http\Controllers\LegacyNotificationController::class)->markRead($request);
    })->whereNumber('id');

    Route::get('/api/forms', [\App\Http\Controllers\LegacyLookupController::class, 'forms']);
    Route::get('/api/forms/{id}', function (\Illuminate\Http\Request $request, int $id) {
        $request->query->set('id', $id);

        return app(\App\Http\Controllers\LegacyLookupController::class)->formDetails($request);
    })->whereNumber('id');

    Route::get('/api/forms/{id}/responses', function (\Illuminate\Http\Request $request, int $id) {
        $request->query->set('form_id', $id);

        return app(\App\Http\Controllers\LegacyLookupController::class)->responses($request);
    })->whereNumber('id');
    Route::get('/api/forms/{id}/responses/export', function (\Illuminate\Http\Request $request, int $id) {
        $request->query->set('form_id', $id);

        return app(\App\Http\Controllers\LegacyLookupController::class)->exportResponses($request);
    })->whereNumber('id');
    Route::get('/api/responses/{id}', function (\Illuminate\Http\Request $request, int $id) {
        $request->query->set('id', $id);

        return app(\App\Http\Controllers\LegacyLookupController::class)->responseDetails($request);
    })->whereNumber('id');

    Route::post('/api/forms', [\App\Http\Controllers\LegacyFormWriteController::class, 'saveForm']);
    Route::put('/api/forms/{id}', function (\Illuminate\Http\Request $request, int $id) {
        $payload = $request->json()->all();
        $payload['form_id'] = $id;
        $request->json()->replace($payload);

        return app(\App\Http\Controllers\LegacyFormWriteController::class)->updateForm($request);
    })->whereNumber('id');
    Route::delete('/api/forms/{id}', function (\Illuminate\Http\Request $request, int $id) {
        $payload = $request->json()->all();
        $payload['form_id'] = $id;
        $request->json()->replace($payload);

        return app(\App\Http\Controllers\LegacyFormWriteController::class)->deleteForm($request);
    })->whereNumber('id');
});

/*
|--------------------------------------------------------------------------
| Super-admin-only endpoints
|--------------------------------------------------------------------------
*/
Route::middleware('legacy.superadmin')->group(function (): void {
    Route::get('/api/admin/forms', [\App\Http\Controllers\LegacyAdminFormController::class, 'allForms']);

    Route::get('/api/admin/audit-logs', [\App\Http\Controllers\LegacyAuditLogController::class, 'index']);

    Route::post('/api/banner', [\App\Http\Controllers\LegacyBannerController::class, 'upload']);
    Route::delete('/api/banner', [\App\Http\Controllers\LegacyBannerController::class, 'remove']);

    Route::get('/api/users', [\App\Http\Controllers\LegacyUserController::class, 'users']);
    Route::post('/api/users', [\App\Http\Controllers\LegacyUserController::class, 'create']);
    Route::delete('/api/users/{id}', function (\Illuminate\Http\Request $request, int $id) {
        $payload = $request->json()->all();
        $payload['user_id'] = $id;
        $request->json()->replace($payload);

        return app(\App\Http\Controllers\LegacyUserController::class)->delete($request);
    })->whereNumber('id');
    Route::patch('/api/users/{id}/password', function (\Illuminate\Http\Request $request, int $id) {
        $payload = $request->json()->all();
        $payload['user_id'] = $id;
        $request->json()->replace($payload);

        return app(\App\Http\Controllers\LegacyUserController::class)->changePassword($request);
    })->whereNumber('id');
    Route::post('/users/{id}/password-reset-code', [\App\Http\Controllers\PasswordResetVerificationController::class, 'requestCode'])->whereNumber('id');
    Route::post('/users/{id}/password-reset-code/verify', [\App\Http\Controllers\PasswordResetVerificationController::class, 'verifyCode'])->whereNumber('id');
    Route::post('/users/{id}/email', [\App\Http\Controllers\LegacyUserController::class, 'setEmail'])->whereNumber('id');
});

Route::get('/{path}', $serveReactApp)
    ->where('path', '^(?!.*\.).*$');
