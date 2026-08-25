# Form Builder Backend

Backend workspace for the Form Builder system. The primary runtime is the Laravel app in `laravel/`, which serves both the compiled React frontend and the API routes. The root PHP endpoint files remain for compatibility, reference, and migration tests while the Laravel conversion continues.

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

- PHP 8+
- Laravel dependencies in `laravel/vendor`
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

Legacy root PHP database configuration is still loaded in `db.php` for compatibility workflows.

Environment variables used by the legacy compatibility path and bootstrap scripts:

```text
FB_DB_HOST
FB_DB_NAME
FB_DB_USER
FB_DB_PASS
FB_ALLOW_TEST_GUARD
FB_ALLOWED_ORIGINS
FB_MIN_PASSWORD_LENGTH
FB_BOOTSTRAP_ADMIN_USERNAME
FB_BOOTSTRAP_ADMIN_PASSWORD
```

Optional legacy local override:

```text
db.local.php
```

Create it from:

```text
db.local.example.php
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

Schema lives entirely in Laravel migrations:

```text
laravel/database/migrations/
```

```powershell
cd laravel
php artisan migrate
```

## Important Endpoints

Laravel defines production routes in `laravel/routes/web.php`. Every endpoint is reached only through its native `/api/...` route — the old `.php`-suffixed aliases have been removed.

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

Settings:

- `POST /api/banner`
- `DELETE /api/banner`

Notifications:

- `GET /api/notifications`
- `GET /api/notifications/pending`
- `POST /api/notifications/{id}/read`
- `POST /api/notifications/{id}/acknowledge`

## Initial Super Admin Bootstrap

For a clean production database, create the first Super Admin from the server CLI only:

```powershell
$env:FB_BOOTSTRAP_ADMIN_USERNAME="admin"
$env:FB_BOOTSTRAP_ADMIN_PASSWORD="Use-A-Strong-Unique-Password-123"
php bootstrap_super_admin.php
```

The script refuses web requests, enforces the server password policy, and aborts if any Super Admin already exists.

## Test Endpoints

These are for E2E tests only:

- `test_database_guard.php`
- `test_reset_database.php`
- `test_audit_logs.php`

They require `allow_test_guard => true` and must not be enabled in production.

## Laravel Tests

From `laravel/`:

```powershell
php artisan test
```

Feature tests cover the Laravel compatibility controllers and route behavior.

## Security Notes

- Mutating authenticated endpoints should call `fb_require_csrf()` or use the Laravel equivalent.
- Super Admin endpoints should call `fb_require_super_admin()` or use the Laravel equivalent.
- Public endpoints must validate all submitted data server-side.
- File uploads must stay restricted to validated PNG files.
- Production should use HTTPS.
- Production should block or remove `test_*.php`.
- Production should point the web root at `laravel/public`, not at the repository root.

## Audit Logs

Audit logging is handled by `audit_helpers.php` and the Laravel compatibility controllers.

Audited events include:

- `USER_LOGIN`
- `USER_LOGOUT`
- `USER_CREATED`
- `USER_PASSWORD_CHANGED`
- `USER_DELETED`
- `FORM_CREATED`
- `FORM_UPDATED`
- `FORM_DELETED`
- `RESPONSES_EXPORTED`
- `BANNER_UPLOADED`
- `BANNER_REMOVED`
