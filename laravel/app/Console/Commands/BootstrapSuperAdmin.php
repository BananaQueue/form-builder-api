<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Laravel port of the legacy CLI script bootstrap_super_admin.php.
 *
 * Seeds the very first super-admin account. It is intentionally CLI-only
 * (an artisan command never runs over HTTP) and refuses to run once any
 * super admin already exists, so it cannot be used to mint extra admins.
 */
class BootstrapSuperAdmin extends Command
{
    protected $signature = 'fb:bootstrap-super-admin {username? : Super admin username} {password? : Super admin password}';

    protected $description = 'Create the initial super admin account (aborts if one already exists)';

    public function handle(): int
    {
        // Environment variables take precedence over positional arguments,
        // matching the legacy bootstrap_super_admin.php contract.
        $username = trim((string) (env('FB_BOOTSTRAP_ADMIN_USERNAME') ?: ($this->argument('username') ?? '')));
        $password = (string) (env('FB_BOOTSTRAP_ADMIN_PASSWORD') ?: ($this->argument('password') ?? ''));

        if ($username === '' || $password === '') {
            $this->error('Usage: set FB_BOOTSTRAP_ADMIN_USERNAME and FB_BOOTSTRAP_ADMIN_PASSWORD, or pass username and password as arguments.');

            return self::FAILURE;
        }

        $passwordError = $this->passwordPolicyError($password);
        if ($passwordError !== null) {
            $this->error($passwordError);

            return self::FAILURE;
        }

        try {
            $abortReason = DB::transaction(function () use ($username, $password): ?string {
                if (DB::select("SELECT id FROM users WHERE role = 'super_admin' LIMIT 1 FOR UPDATE")) {
                    return 'A Super Admin already exists. Bootstrap aborted.';
                }

                if (DB::select('SELECT id FROM users WHERE username = ? FOR UPDATE', [$username])) {
                    return 'Username already exists. Bootstrap aborted.';
                }

                DB::insert("INSERT INTO users (username, role, password_hash) VALUES (?, 'super_admin', ?)", [
                    $username,
                    password_hash($password, PASSWORD_BCRYPT, ['cost' => (int) env('BCRYPT_ROUNDS', 12)]),
                ]);

                return null;
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Failed to bootstrap Super Admin.');

            return self::FAILURE;
        }

        if ($abortReason !== null) {
            $this->error($abortReason);

            return self::FAILURE;
        }

        $this->info("Super Admin created: {$username}");

        return self::SUCCESS;
    }

    /**
     * Mirror of LegacyUserController's password policy so the bootstrap admin
     * is held to the same minimum strength as user-management-created accounts.
     */
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
}
