<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class LegacyAuthController extends Controller
{
    public function checkSession(): JsonResponse
    {
        if (session('logged_in') === true) {
            return response()->json([
                'logged_in' => true,
                'username' => session('username'),
                'user_id' => session('user_id'),
                'role' => session('role', 'user'),
                'csrf_token' => csrf_token(),
            ]);
        }

        return response()->json(['logged_in' => false]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->json()->all();

        if (! is_array($data) || empty($data['username']) || empty($data['password'])) {
            return response()->json(['error' => 'Username and password are required'], 400);
        }

        $username = trim((string) $data['username']);
        $password = (string) $data['password'];

        if ($this->isLoginRateLimited($username, $request->ip())) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        try {
            $user = DB::table('users')
                ->select('id', 'username', 'password_hash', 'role')
                ->where('username', $username)
                ->first();

            $passwordCorrect = $user && password_verify($password, $user->password_hash);

            if (! $passwordCorrect) {
                $this->recordLoginFailure($username, $request->ip());

                return response()->json(['error' => 'Invalid credentials'], 401);
            }

            $request->session()->regenerate();
            $this->clearLoginFailures($username, $request->ip());

            $request->session()->put([
                'user_id' => $user->id,
                'username' => $user->username,
                'role' => $user->role,
                'logged_in' => true,
            ]);

            $csrfToken = csrf_token();
            $this->auditLogin($request, $user);

            return response()->json([
                'success' => true,
                'username' => $user->username,
                'role' => $user->role,
                'csrf_token' => $csrfToken,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Server error'], 500);
        }
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->session()->get('logged_in') === true) {
            $this->auditLogout($request);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['success' => true]);
    }

    private function auditLogin(Request $request, object $user): void
    {
        try {
            DB::table('audit_logs')->insert([
                'actor_user_id' => $user->id,
                'actor_username' => $user->username,
                'actor_role' => $user->role,
                'action' => 'USER_LOGIN',
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'entity_label' => $user->username,
                'metadata' => json_encode(new \stdClass()),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function auditLogout(Request $request): void
    {
        try {
            DB::table('audit_logs')->insert([
                'actor_user_id' => $request->session()->get('user_id'),
                'actor_username' => $request->session()->get('username'),
                'actor_role' => $request->session()->get('role'),
                'action' => 'USER_LOGOUT',
                'entity_type' => 'user',
                'entity_id' => $request->session()->get('user_id'),
                'entity_label' => $request->session()->get('username'),
                'metadata' => json_encode(new \stdClass()),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function isLoginRateLimited(string $username, ?string $ip): bool
    {
        $state = Cache::get($this->loginRateLimitKey($username, $ip));

        return is_array($state) && ((int) ($state['locked_until'] ?? 0)) > time();
    }

    private function recordLoginFailure(string $username, ?string $ip): void
    {
        $key = $this->loginRateLimitKey($username, $ip);
        $now = time();
        $windowSeconds = 15 * 60;
        $lockSeconds = 5 * 60;
        $state = Cache::get($key);

        if (! is_array($state) || ((int) ($state['window_started'] ?? 0)) < ($now - $windowSeconds)) {
            $state = ['window_started' => $now, 'failures' => 0, 'locked_until' => 0];
        }

        $state['failures'] = ((int) ($state['failures'] ?? 0)) + 1;
        if ($state['failures'] >= 8) {
            $state['locked_until'] = $now + $lockSeconds;
        }

        Cache::put($key, $state, $windowSeconds + $lockSeconds);
    }

    private function clearLoginFailures(string $username, ?string $ip): void
    {
        Cache::forget($this->loginRateLimitKey($username, $ip));
    }

    private function loginRateLimitKey(string $username, ?string $ip): string
    {
        return 'legacy_login:'.hash('sha256', strtolower(trim($username)).'|'.($ip ?: 'unknown'));
    }
}