<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class LegacyUserController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $authError = $this->requireSuperAdmin($request);
        if ($authError) {
            return $authError;
        }

        try {
            $users = DB::select(<<<'SQL'
                SELECT
                    u.id,
                    u.username,
                    u.role,
                    u.created_at,
                    COUNT(f.id) AS form_count
                FROM users u
                LEFT JOIN forms f ON f.created_by = u.id
                GROUP BY u.id, u.username, u.role, u.created_at
                ORDER BY u.created_at ASC
                SQL);

            return response()->json(['success' => true, 'users' => $users]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'error' => 'Failed to retrieve users'], 500);
        }
    }

    public function create(Request $request): JsonResponse
    {
        $authError = $this->requireSuperAdmin($request);
        if ($authError) {
            return $authError;
        }

        $data = $request->json()->all();
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $role = (string) ($data['role'] ?? 'user');

        if ($username === '' || $password === '') {
            return response()->json(['success' => false, 'error' => 'Username and password are required'], 400);
        }

        if (! in_array($role, ['user', 'super_admin'], true)) {
            return response()->json(['success' => false, 'error' => 'Invalid role'], 400);
        }

        $passwordError = $this->passwordPolicyError($password);
        if ($passwordError) {
            return response()->json(['success' => false, 'error' => $passwordError], 400);
        }

        try {
            $existing = DB::select('SELECT id FROM users WHERE username = ?', [$username]);
            if ($existing) {
                return response()->json(['success' => false, 'error' => 'Username already exists'], 409);
            }

            DB::insert('INSERT INTO users (username, role, password_hash) VALUES (?, ?, ?)', [
                $username,
                $role,
                password_hash($password, PASSWORD_BCRYPT),
            ]);
            $newUserId = (int) DB::getPdo()->lastInsertId();

            $this->audit($request, 'USER_CREATED', [
                'entity_type' => 'user',
                'entity_id' => $newUserId,
                'entity_label' => $username,
                'metadata' => ['role' => $role],
            ]);

            return response()->json(['success' => true, 'user_id' => $newUserId]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'error' => 'Failed to create user'], 500);
        }
    }

    public function delete(Request $request): JsonResponse
    {
        $authError = $this->requireSuperAdmin($request);
        if ($authError) {
            return $authError;
        }

        $data = $request->json()->all();
        $userId = isset($data['user_id']) ? (int) $data['user_id'] : 0;
        $currentUserId = (int) $request->session()->get('user_id');

        if ($userId <= 0) {
            return response()->json(['success' => false, 'error' => 'Invalid user ID'], 400);
        }

        if ($userId === $currentUserId) {
            return response()->json(['success' => false, 'error' => 'You cannot delete your own account'], 400);
        }

        try {
            return DB::transaction(function () use ($request, $userId): JsonResponse {
                $targetUsers = DB::select('SELECT username, role FROM users WHERE id = ? FOR UPDATE', [$userId]);
                $targetUser = $targetUsers[0] ?? null;

                if (! $targetUser) {
                    return response()->json(['success' => false, 'error' => 'User not found'], 404);
                }

                if ($targetUser->role === 'super_admin') {
                    $superAdmins = DB::select("SELECT id FROM users WHERE role = 'super_admin' FOR UPDATE");
                    if (count($superAdmins) <= 1) {
                        return response()->json(['success' => false, 'error' => 'Cannot delete the last Super Admin account'], 409);
                    }
                }

                $formCounts = DB::select('SELECT COUNT(*) as count FROM forms WHERE created_by = ?', [$userId]);
                $reassignedFormCount = (int) ($formCounts[0]->count ?? 0);

                DB::update('UPDATE forms SET created_by = NULL WHERE created_by = ?', [$userId]);
                $deleted = DB::delete('DELETE FROM users WHERE id = ?', [$userId]);

                if ($deleted === 0) {
                    return response()->json(['success' => false, 'error' => 'User not found'], 404);
                }

                $this->audit($request, 'USER_DELETED', [
                    'entity_type' => 'user',
                    'entity_id' => $userId,
                    'entity_label' => $targetUser->username,
                    'metadata' => [
                        'role' => $targetUser->role,
                        'forms_unassigned' => $reassignedFormCount,
                    ],
                ]);

                return response()->json(['success' => true]);
            });
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'error' => 'Failed to delete user'], 500);
        }
    }

    public function changePassword(Request $request): JsonResponse
    {
        $authError = $this->requireSuperAdmin($request);
        if ($authError) {
            return $authError;
        }

        $data = $request->json()->all();
        $userId = isset($data['user_id']) ? (int) $data['user_id'] : 0;
        $newPassword = (string) ($data['new_password'] ?? '');

        if ($userId <= 0) {
            return response()->json(['success' => false, 'error' => 'Invalid user ID'], 400);
        }

        $passwordError = $this->passwordPolicyError($newPassword);
        if ($passwordError) {
            return response()->json(['success' => false, 'error' => $passwordError], 400);
        }

        try {
            $updated = DB::update('UPDATE users SET password_hash = ? WHERE id = ?', [
                password_hash($newPassword, PASSWORD_BCRYPT),
                $userId,
            ]);

            if ($updated === 0) {
                return response()->json(['success' => false, 'error' => 'User not found'], 404);
            }

            $this->audit($request, 'USER_PASSWORD_CHANGED', [
                'entity_type' => 'user',
                'entity_id' => $userId,
            ]);

            return response()->json(['success' => true]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'error' => 'Failed to update password'], 500);
        }
    }

    private function requireSuperAdmin(Request $request): ?JsonResponse
    {
        if ($request->session()->get('logged_in') !== true) {
            return response()->json(['error' => 'Authentication required'], 401);
        }

        if ($request->session()->get('role') !== 'super_admin') {
            return response()->json(['error' => 'Super admin access required'], 403);
        }

        return null;
    }

    private function passwordPolicyError(string $password): ?string
    {
        $minLength = max(12, (int) env('FB_MIN_PASSWORD_LENGTH', 12));
        if (strlen($password) < $minLength) {
            return "Password must be at least {$minLength} characters";
        }

        if (! preg_match('/[A-Z]/', $password)) {
            return 'Password must include at least one uppercase letter';
        }

        if (! preg_match('/[a-z]/', $password)) {
            return 'Password must include at least one lowercase letter';
        }

        if (! preg_match('/[0-9]/', $password)) {
            return 'Password must include at least one number';
        }

        return null;
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