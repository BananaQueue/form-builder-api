# Form Builder Backend

Backend workspace for the Form Builder system. The Laravel app in `laravel/` is the entire backend runtime — it serves the compiled React frontend and every API route. There are no standalone PHP endpoint files at the repo root anymore; the pre-Laravel PHP app was fully retired.

## Responsibilities

- Authentication and session management
- CSRF validation
- User and Super Admin APIs
- Form create/update/delete APIs
- Public form lookup and response submission
- Response viewing and CSV export
- Banner upload/removal
- Notifications
- Audit logging

## Requirements

- PHP 8.4.1+ (the app itself targets `^8.3`, but `composer.lock` pins Symfony components that hard-require 8.4.1 — anything older won't install)
- Composer dependencies installed in `laravel/vendor`
- MariaDB/MySQL
- A web server pointed at `laravel/public`, or `php artisan serve` for local development

## Configuration

Primary configuration lives in:

```text
laravel/.env
```

Important Laravel values:

```text
APP_TIMEZONE
DB_CONNECTION
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD
DB_TIMEZONE
SESSION_DRIVER
```

A handful of `FB_*` env vars are read directly (not through `.env`'s normal `config()` layer, but via `env()` calls in specific commands/controllers):

```text
FB_BOOTSTRAP_ADMIN_USERNAME   # first super admin's username, used by fb:bootstrap-super-admin
FB_BOOTSTRAP_ADMIN_PASSWORD   # first super admin's password, used by fb:bootstrap-super-admin
FB_MIN_PASSWORD_LENGTH        # password policy floor, defaults to 12 if unset
FB_ALLOW_TEST_GUARD           # must be '1' to allow the test_*.php helper routes outside the testing env — never set in production
FB_TEST_RESET_TOKEN           # bearer token the test_reset_database.php route checks; defaults to 'local-e2e-reset' if unset
```

Do not commit production secrets.

## Local Laravel Run

From `laravel/`:

```powershell
php artisan serve --host=0.0.0.0 --port=8000
```

Open:

```text
http://127.0.0.1:8000
```

The React app is served from `laravel/public/app` after the frontend build runs.

## Database

Schema lives entirely in one Laravel migration:

```text
laravel/database/migrations/
```

```powershell
cd laravel
php artisan migrate
```

`php artisan migrate` is safe against a fresh database only. Migrating the pre-existing production database is a separate, deliberate, manual one-time step — `deploy/deploy.ps1` refuses to run it automatically and points at the procedure documented in the sibling `form-builder-app` repo's `docs/DEPLOYMENT.md`.

## Important Endpoints

Laravel defines production routes in `laravel/routes/web.php`. There are no `.php`-suffixed route aliases. Almost every route below is `/api`-prefixed; three Super Admin routes (listed under Super Admin) deliberately aren't — still native routes, still gated by the same `legacy.superadmin` middleware as everything else in that group.

Authentication:

- `POST /api/login`
- `POST /api/logout`
- `GET /api/session`

Forms:

- `POST /api/forms`
- `PUT /api/forms/{id}`
- `DELETE /api/forms/{id}`
- `GET /api/forms`
- `GET /api/admin/forms`
- `GET /api/forms/{id}`
- `GET /api/public/forms/{code}`

Responses:

- `POST /api/public/forms/{id}/responses`
- `GET /api/forms/{id}/responses`
- `GET /api/responses/{id}`
- `GET /api/forms/{id}/responses/export`

Super Admin:

- `GET /api/users`
- `POST /api/users`
- `PATCH /api/users/{id}/password`
- `DELETE /api/users/{id}`
- `GET /api/admin/audit-logs`
- `POST /users/{id}/password-reset-code` — not `/api`-prefixed
- `POST /users/{id}/password-reset-code/verify` — not `/api`-prefixed
- `POST /users/{id}/email` — not `/api`-prefixed

Settings:

- `POST /api/banner`
- `DELETE /api/banner`

Notifications:

- `GET /api/notifications`
- `GET /api/notifications/pending`
- `POST /api/notifications/{id}/read`
- `POST /api/notifications/{id}/acknowledge`

## Initial Super Admin Bootstrap

For a clean database, create the first Super Admin from the server CLI only — this is an artisan command, not a standalone script:

```powershell
cd laravel
$env:FB_BOOTSTRAP_ADMIN_USERNAME="admin"
$env:FB_BOOTSTRAP_ADMIN_PASSWORD="Use-A-Strong-Unique-Password-123"
php artisan fb:bootstrap-super-admin
```

The username/password can also be passed as positional arguments instead of env vars. The command is CLI-only (never routed over HTTP), enforces the server password policy, and aborts if any Super Admin already exists.

## Test Endpoints

These routes exist for E2E tests only:

- `test_database_guard.php`
- `test_reset_database.php`
- `test_audit_logs.php`
- `test_last_reset_code.php`

They're guarded by `FB_ALLOW_TEST_GUARD=1` (or the `testing` environment) plus a database-name check (the target DB name must end in `test`). The reset and audit-log-lookup routes additionally require a token in the `X-E2E-Reset-Token` header (`FB_TEST_RESET_TOKEN`). Never set `FB_ALLOW_TEST_GUARD` in production.

## Laravel Tests

From `laravel/`:

```powershell
php artisan test
```

Feature tests cover every `Legacy*Controller` and route behavior. Most mock the `DB` facade and assert on the exact SQL issued, rather than hitting a real database.

## Security Notes

- Mutating authenticated endpoints are CSRF-protected via Laravel's own `validateCsrfTokens` middleware (`bootstrap/app.php`), with a narrow, explicit exemption list for pre-session public endpoints.
- Authenticated/Super Admin route groups are gated by the `legacy.auth`/`legacy.superadmin` middleware aliases, not per-method checks.
- Public endpoints must validate all submitted data server-side.
- File uploads must stay restricted to validated PNG files.
- Production should use HTTPS.
- Production should never set `FB_ALLOW_TEST_GUARD`.
- Production should point the web root at `laravel/public`, not at the repository root.

## Audit Logs

Audit logging is handled inline in each `Legacy*Controller` (an `audit()` helper method per controller, writing to the `audit_logs` table) — there's no shared helper file.

Audited events include:

- `USER_LOGIN`
- `USER_LOGOUT`
- `USER_CREATED`
- `USER_PASSWORD_CHANGED`
- `USER_EMAIL_UPDATED`
- `USER_DELETED`
- `PASSWORD_RESET_CODE_REQUESTED`
- `FORM_CREATED`
- `FORM_UPDATED`
- `FORM_DELETED`
- `RESPONSES_EXPORTED`
- `BANNER_UPLOADED`
- `BANNER_REMOVED`
