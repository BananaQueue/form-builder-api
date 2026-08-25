<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class LegacyTestController extends Controller
{
    public function databaseGuard(): JsonResponse
    {
        if (! $this->guardEnabled()) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $databaseName = $this->databaseName();
        if (! $this->isTestDatabase($databaseName)) {
            return response()->json([
                'ok' => false,
                'error' => 'E2E tests must use a dedicated test database.',
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'database' => $databaseName,
        ]);
    }

    public function resetDatabase(Request $request): JsonResponse
    {
        if (! $this->guardEnabled()) {
            return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        }

        $databaseName = $this->databaseName();
        if (! $this->isTestDatabase($databaseName)) {
            return response()->json(['ok' => false, 'error' => 'E2E reset must use a dedicated test database.'], 500);
        }

        if (! $this->validToken($request)) {
            return response()->json(['ok' => false, 'error' => 'Invalid reset token'], 403);
        }

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach ([
                'answers',
                'responses',
                'question_options',
                'questions',
                'notifications',
                'forms',
                'audit_logs',
                'password_reset_codes',
                'users',
                'categories',
            ] as $table) {
                DB::statement("DELETE FROM `{$table}`");
                DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = 1");
            }
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');

            foreach ([[1, 'General'], [2, 'External'], [3, 'Internal']] as $category) {
                DB::insert('INSERT INTO categories (id, name) VALUES (?, ?)', $category);
            }

            $passwordHash = password_hash('PlaywrightTest123!', PASSWORD_DEFAULT);
            foreach ([
                [1, 'e2e_super_admin', 'super_admin', $passwordHash],
                [2, 'e2e_regular_user', 'user', $passwordHash],
                [3, 'e2e_regular_user_two', 'user', $passwordHash],
            ] as $user) {
                DB::insert('INSERT INTO users (id, username, role, password_hash) VALUES (?, ?, ?, ?)', $user);
            }

            return response()->json([
                'ok' => true,
                'database' => $databaseName,
                'seeded' => [
                    'users' => ['e2e_super_admin', 'e2e_regular_user', 'e2e_regular_user_two'],
                    'categories' => ['General', 'External', 'Internal'],
                ],
            ]);
        } catch (Throwable $exception) {
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            } catch (Throwable) {
            }

            report($exception);

            return response()->json([
                'ok' => false,
                'error' => 'Test database reset failed',
                'details' => $exception->getMessage(),
            ], 500);
        }
    }

    public function auditLogs(Request $request): JsonResponse
    {
        if (! $this->guardEnabled()) {
            return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        }

        $databaseName = $this->databaseName();
        if (! $this->isTestDatabase($databaseName)) {
            return response()->json(['ok' => false, 'error' => 'Audit test lookup must use a dedicated test database.'], 500);
        }

        if (! $this->validToken($request)) {
            return response()->json(['ok' => false, 'error' => 'Invalid test token'], 403);
        }

        try {
            $where = [];
            $params = [];

            $action = trim((string) $request->query('action', ''));
            if ($action !== '') {
                $where[] = 'action = ?';
                $params[] = $action;
            }

            $entityLabel = trim((string) $request->query('entity_label', ''));
            if ($entityLabel !== '') {
                $where[] = 'entity_label = ?';
                $params[] = $entityLabel;
            }

            $whereSql = $where === [] ? '' : 'WHERE '.implode(' AND ', $where);
            $logs = DB::select(<<<SQL
                SELECT
                    id,
                    actor_user_id,
                    actor_username,
                    actor_role,
                    action,
                    entity_type,
                    entity_id,
                    entity_label,
                    metadata,
                    created_at
                FROM audit_logs
                {$whereSql}
                ORDER BY id DESC
                LIMIT 20
                SQL, $params);

            return response()->json([
                'ok' => true,
                'database' => $databaseName,
                'logs' => $logs,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'error' => 'Audit test lookup failed',
                'details' => $exception->getMessage(),
            ], 500);
        }
    }

    // Test-only helper: with MAIL_MAILER=log, "sent" emails land as plain
    // text in the Laravel log. This lets E2E tests read the verification
    // code back out the same way a human would per the manual test plan,
    // without ever exposing this endpoint outside the guarded test setup.
    public function lastResetCode(): JsonResponse
    {
        if (! $this->guardEnabled()) {
            return response()->json(['ok' => false, 'error' => 'Not found'], 404);
        }

        $databaseName = $this->databaseName();
        if (! $this->isTestDatabase($databaseName)) {
            return response()->json(['ok' => false, 'error' => 'Test log lookup must use a dedicated test database.'], 500);
        }

        $logPath = storage_path('logs/laravel.log');
        if (! is_file($logPath)) {
            return response()->json(['ok' => false, 'error' => 'No log file found'], 404);
        }

        $contents = file_get_contents($logPath);
        if (! preg_match_all('/font-size:\s*28px[^>]*>(\d{6})</', $contents, $matches)) {
            return response()->json(['ok' => false, 'error' => 'No verification code found in log'], 404);
        }

        return response()->json(['ok' => true, 'code' => end($matches[1])]);
    }

    private function guardEnabled(): bool
    {
        return app()->environment('testing') || env('FB_ALLOW_TEST_GUARD') === '1';
    }

    private function databaseName(): string
    {
        return (string) config('database.connections.'.config('database.default').'.database', '');
    }

    private function isTestDatabase(string $databaseName): bool
    {
        return preg_match('/(^|_)test$/i', $databaseName) === 1;
    }

    private function validToken(Request $request): bool
    {
        $expectedToken = (string) (env('FB_TEST_RESET_TOKEN') ?: 'local-e2e-reset');
        $providedToken = (string) $request->header('X-E2E-Reset-Token', '');

        return $expectedToken !== '' && hash_equals($expectedToken, $providedToken);
    }
}
