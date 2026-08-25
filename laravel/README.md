# Form Builder Laravel Backend

Primary backend for the Form Builder system. It serves both:

- The Laravel-native `/api/...` routes (e.g. `/api/forms`, `/api/forms/{id}`, `/api/public/forms/{code}`) — see `docs/laravel-endpoint-inventory.md` in `form-builder-app` for the full list
- the compiled React frontend from `public/app`

## Requirements

- PHP 8.4+
- Composer dependencies installed in this directory
- MariaDB/MySQL with the `form_builder` schema
- Node/npm in `../../form-builder-app` when rebuilding frontend assets

## Local Run

From this directory:

```powershell
php artisan serve --host=0.0.0.0 --port=8000
```

Open the app locally:

```text
http://127.0.0.1:8000
```

For mobile testing on the same network, use the PC LAN IP:

```text
http://<pc-lan-ip>:8000
```

## Test Server Database

The normal Laravel server uses the database in `.env`, currently `form_builder`. For workflow testing that can create, edit, submit, or delete records, use a separate testing environment pointed at `form_builder_test`.

Create the local testing env once:

```powershell
Copy-Item .env.testing.example .env.testing
```

Then run Laravel on a separate port:

```powershell
php artisan serve --env=testing --host=0.0.0.0 --port=8001
```

Open the test-server app at:

```text
http://127.0.0.1:8001
```

Use this path for destructive smoke tests. Keep `:8000` for the main local database and `:8001` for the test database.

## Frontend Build

The React app still lives in `../../form-builder-app`. Build it from there:

```powershell
cd ..\..\form-builder-app
npm run build
```

Vite writes the compiled frontend into:

```text
../form-builder-api/laravel/public/app
```

Laravel serves normal React routes such as `/` and `/form/{code}` from `public/app/index.html`. File-like paths such as missing `.png`, `.js`, or `.css` files return `404` instead of the React shell.

## API Routes

Every endpoint is reached through its Laravel-native `/api/...` route only — there are no `.php`-suffixed compatibility aliases left. Controllers are still named `Legacy*Controller` because they carry over the raw-SQL implementation style from the pre-Laravel PHP app, not because of how they're routed. The four `test_*.php` routes that remain (`test_database_guard.php`, `test_reset_database.php`, `test_audit_logs.php`, `test_last_reset_code.php`) are guarded E2E test helpers with no native counterpart, not legacy aliases.

## Configuration

Important `.env` values:

```text
APP_TIMEZONE=Asia/Singapore
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=form_builder
DB_USERNAME=root
DB_PASSWORD=
DB_TIMEZONE=+08:00
SESSION_DRIVER=file
```

Do not commit production secrets.

## Tests

Run Laravel tests:

```powershell
php artisan test
```

Run frontend/static regression tests from `../../form-builder-app`:

```powershell
npm run lint
npm run test:api
```

Known lint state: the frontend currently has existing React hook dependency warnings but no lint errors.

## Current Conversion Notes

- Laravel is now the main host for normal testing.
- Vite remains the frontend development/build tool.
- During Vite development, `/api/*` proxies to Laravel on `127.0.0.1:8000`.
- In production builds served by Laravel, frontend API calls use same-origin root endpoints.
- Uploaded banner files are served from `public/uploads/banner.png`.
