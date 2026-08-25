<?php

namespace App\Http\Controllers;

use App\Mail\SuperAdminPasswordResetCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class PasswordResetVerificationController extends Controller
{
    private const CODE_TTL_MINUTES = 10;
    private const MAX_REQUESTS_PER_HOUR = 5;
    private const MAX_VERIFY_ATTEMPTS = 5;

    // Step 1: generate a code, email it to the TARGET account, hand back a token.
    public function requestCode(Request $request, int $id): JsonResponse
    {
        $target = DB::table('users')->select('id', 'username', 'role', 'email')->where('id', $id)->first();

        if (! $target) {
            return response()->json(['success' => false, 'error' => 'User not found'], 404);
        }

        if ($target->role !== 'super_admin') {
            return response()->json(['success' => false, 'error' => 'This verification step only applies to Super Admin accounts'], 400);
        }

        if (empty($target->email)) {
            return response()->json([
                'success' => false,
                'error' => 'This account has no recovery email on file. Set one before changing its password.',
                'missing_email' => true,
            ], 422);
        }

        // Simple per-target rate limit: stops someone hammering another
        // admin's inbox with codes, or brute-forcing by requesting many.
        $rateLimitKey = 'password_reset_code_requests:'.$target->id;
        $attempts = (int) Cache::get($rateLimitKey, 0);
        if ($attempts >= self::MAX_REQUESTS_PER_HOUR) {
            return response()->json(['success' => false, 'error' => 'Too many reset codes requested for this account. Try again later.'], 429);
        }
        Cache::put($rateLimitKey, $attempts + 1, now()->addHour());

        $code = (string) random_int(100000, 999999);
        $token = Str::random(48);

        try {
            DB::table('password_reset_codes')->insert([
                'user_id' => $target->id,
                'requested_by_user_id' => $request->session()->get('user_id'),
                'code_hash' => Hash::make($code),
                'token' => $token,
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Mail::to($target->email)->send(new SuperAdminPasswordResetCode(
                username: $target->username,
                code: $code,
                expiresInMinutes: self::CODE_TTL_MINUTES,
            ));

            $this->audit($request, 'PASSWORD_RESET_CODE_REQUESTED', [
                'entity_type' => 'user',
                'entity_id' => $target->id,
                'entity_label' => $target->username,
            ]);

            return response()->json([
                'success' => true,
                'token' => $token,
                'masked_email' => $this->maskEmail($target->email),
                'expires_in_minutes' => self::CODE_TTL_MINUTES,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'error' => 'Failed to send verification code'], 500);
        }
    }

    // Step 2: check the code the admin typed matches the pending request.
    public function verifyCode(Request $request, int $id): JsonResponse
    {
        $token = (string) ($request->json('token') ?? '');
        $code = (string) ($request->json('code') ?? '');

        if ($token === '' || $code === '') {
            return response()->json(['success' => false, 'error' => 'Token and code are required'], 400);
        }

        // Guessing rate limit: caps how many codes can be tried against a
        // single pending token before the admin has to request a fresh one.
        $rateLimitKey = $this->verifyRateLimitKey($id, $token);
        $attempts = (int) Cache::get($rateLimitKey, 0);
        if ($attempts >= self::MAX_VERIFY_ATTEMPTS) {
            return response()->json(['success' => false, 'error' => 'Too many incorrect attempts. Request a new code.'], 429);
        }

        $row = DB::table('password_reset_codes')
            ->where('user_id', $id)
            ->where('token', $token)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if (! $row || ! Hash::check($code, $row->code_hash)) {
            Cache::put($rateLimitKey, $attempts + 1, now()->addMinutes(self::CODE_TTL_MINUTES));

            return response()->json(['success' => false, 'error' => 'Invalid or expired code'], 400);
        }

        DB::table('password_reset_codes')->where('id', $row->id)->update([
            'verified_at' => now(),
            'updated_at' => now(),
        ]);

        Cache::forget($rateLimitKey);

        return response()->json(['success' => true, 'token' => $token]);
    }

    private function verifyRateLimitKey(int $userId, string $token): string
    {
        return 'password_reset_code_verify:'.$userId.':'.hash('sha256', $token);
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $visible = mb_substr($local, 0, 1);

        return $visible.str_repeat('*', max(1, mb_strlen($local) - 1)).'@'.$domain;
    }

    private function audit(Request $request, string $action, array $data): void
    {
        try {
            DB::table('audit_logs')->insert([
                'actor_user_id' => $request->session()->get('user_id'),
                'actor_username' => $request->session()->get('username'),
                'actor_role' => $request->session()->get('role'),
                'action' => $action,
                'entity_type' => $data['entity_type'] ?? null,
                'entity_id' => $data['entity_id'] ?? null,
                'entity_label' => $data['entity_label'] ?? null,
                'metadata' => json_encode($data['metadata'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
