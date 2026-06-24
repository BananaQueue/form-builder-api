<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/_fb_laravel_health', function () {
    return response()->json([
        'ok' => true,
        'app' => config('app.name'),
        'backend' => 'laravel',
    ]);
});

Route::get('/check_session.php', [\App\Http\Controllers\LegacyAuthController::class, 'checkSession']);
Route::post('/login.php', [\App\Http\Controllers\LegacyAuthController::class, 'login']);
Route::post('/logout.php', [\App\Http\Controllers\LegacyAuthController::class, 'logout']);
Route::get('/get_all_forms.php', [\App\Http\Controllers\LegacyAdminFormController::class, 'allForms']);
Route::get('/get_audit_logs.php', [\App\Http\Controllers\LegacyAuditLogController::class, 'index']);
Route::get('/get_notifications.php', [\App\Http\Controllers\LegacyNotificationController::class, 'notifications']);
Route::get('/get_pending_notifications.php', [\App\Http\Controllers\LegacyNotificationController::class, 'pending']);
Route::post('/acknowledge_notification.php', [\App\Http\Controllers\LegacyNotificationController::class, 'acknowledge']);
Route::post('/mark_notification_read.php', [\App\Http\Controllers\LegacyNotificationController::class, 'markRead']);
Route::post('/upload_banner.php', [\App\Http\Controllers\LegacyBannerController::class, 'upload']);
Route::post('/remove_banner.php', [\App\Http\Controllers\LegacyBannerController::class, 'remove']);
Route::get('/get_users.php', [\App\Http\Controllers\LegacyUserController::class, 'users']);
Route::post('/create_user_api.php', [\App\Http\Controllers\LegacyUserController::class, 'create']);
Route::post('/delete_user.php', [\App\Http\Controllers\LegacyUserController::class, 'delete']);
Route::post('/change_password.php', [\App\Http\Controllers\LegacyUserController::class, 'changePassword']);
Route::get('/get_categories.php', [\App\Http\Controllers\LegacyLookupController::class, 'categories']);
Route::get('/get_forms.php', [\App\Http\Controllers\LegacyLookupController::class, 'forms']);
Route::get('/get_form_details.php', [\App\Http\Controllers\LegacyLookupController::class, 'formDetails']);
Route::get('/get_form_by_code.php', [\App\Http\Controllers\LegacyLookupController::class, 'publicFormByCode']);
Route::get('/get_responses.php', [\App\Http\Controllers\LegacyLookupController::class, 'responses']);
Route::get('/get_response_details.php', [\App\Http\Controllers\LegacyLookupController::class, 'responseDetails']);
Route::get('/export_responses.php', [\App\Http\Controllers\LegacyLookupController::class, 'exportResponses']);
Route::post('/submit_response.php', [\App\Http\Controllers\LegacySubmissionController::class, 'submitResponse']);
Route::post('/save_form.php', [\App\Http\Controllers\LegacyFormWriteController::class, 'saveForm']);
Route::post('/update_form.php', [\App\Http\Controllers\LegacyFormWriteController::class, 'updateForm']);
Route::post('/delete_form.php', [\App\Http\Controllers\LegacyFormWriteController::class, 'deleteForm']);
