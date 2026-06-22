# Form Builder API

PHP API for the Form Builder system. It is consumed by the React frontend in `../form-builder-app`.

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
- MariaDB/MySQL
- Apache/XAMPP or equivalent PHP server

## Configuration

Database configuration is loaded in `db.php`.

Environment variables:

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

Optional local override:

```text
db.local.php
```

Create it from:

```text
db.local.example.php
```

Do not commit production secrets.

## Database

Fresh schema:

```text
../form-builder-app/src/form_builder.sql
```

Incremental migrations:

```text
migrations/
```

Apply migrations in numeric order.

## Important Endpoints

Authentication:

- `login.php`
- `logout.php`
- `check_session.php`

Forms:

- `save_form.php`
- `update_form.php`
- `delete_form.php`
- `get_forms.php`
- `get_all_forms.php`
- `get_form_details.php`
- `get_form_by_code.php`

Responses:

- `submit_response.php`
- `get_responses.php`
- `get_response_details.php`
- `export_responses.php`

Super Admin:

- `get_users.php`
- `create_user_api.php`
- `change_password.php`
- `delete_user.php`
- `get_audit_logs.php`

Settings:

- `upload_banner.php`
- `remove_banner.php`

Notifications:

- `get_notifications.php`
- `get_pending_notifications.php`
- `mark_notification_read.php`
- `acknowledge_notification.php`

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

## Security Notes

- Mutating authenticated endpoints should call `fb_require_csrf()`.
- Super Admin endpoints should call `fb_require_super_admin()`.
- Public endpoints must validate all submitted data server-side.
- File uploads must stay restricted to validated PNG files.
- Production should use HTTPS.
- Production should block or remove `test_*.php`.

## Audit Logs

Audit logging is handled by `audit_helpers.php`.

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

