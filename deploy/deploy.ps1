<#
    FormBuilder - Production deploy (XAMPP / Windows)
    ==================================================================
    Deploys the Laravel backend + built React frontend as a NEW release
    folder, then repoints a "current" link at it. The existing live app
    is never overwritten, so rollback is instant.

    SAFETY
      * Refuses to run if PHP < 8.4.1 (the app cannot run on older PHP).
      * Takes a FULL database backup before touching anything. Aborts if
        the backup fails.
      * Never writes into htdocs.
      * Applies pending Laravel migrations automatically (`artisan migrate
        --force`) after installing dependencies - but ONLY once the target
        database is already Laravel-tracked. Already-applied migrations
        are skipped - Laravel tracks state in the `migrations` table.
      * Has two hard-stops a first production deploy WILL hit and that
        require manual action before re-running the script:
          1. Shared .env has placeholder values (first run ever) - edit it,
             then re-run.
          2. The target database predates this plan's migrations (no
             `migrations` table, or the table exists but the baseline row
             is missing) - do the one-time manual cutover in
             form-builder-app/docs/DEPLOYMENT.md, then re-run.
        A real first deploy is expected to take 2-3 invocations of this
        script, not one.

    TYPICAL USE
      powershell -ExecutionPolicy Bypass -File deploy.ps1 `
          -ApiSource C:\src\form-builder-api `
          -AppSource C:\src\form-builder-app

    Then follow the Apache instructions it prints (first deploy only).
#>

[CmdletBinding()]
param(
    # Path to the form-builder-api repo on this server.
    [Parameter(Mandatory = $true)][string]$ApiSource,
    # Path to the form-builder-app repo on this server.
    [Parameter(Mandatory = $true)][string]$AppSource,

    # Where releases are installed. Apache will point inside here.
    [string]$InstallRoot = 'C:\formbuilder',

    [string]$DbName     = 'form_builder',
    [string]$DbUser     = 'root',
    [string]$DbPassword = '',

    [string]$XamppRoot  = 'C:\xampp'
)

$ErrorActionPreference = 'Stop'
$stamp        = Get-Date -Format 'yyyyMMdd-HHmmss'
$releaseDir   = Join-Path $InstallRoot "releases\$stamp"
$sharedDir    = Join-Path $InstallRoot 'shared'
$backupDir    = Join-Path $InstallRoot 'backups'
$currentLink  = Join-Path $InstallRoot 'current'

function Step($n, $text) {
    Write-Host ""
    Write-Host ("=" * 66) -ForegroundColor Cyan
    Write-Host "  STEP $n : $text" -ForegroundColor Cyan
    Write-Host ("=" * 66) -ForegroundColor Cyan
}
function Ok($t)   { Write-Host "  [ OK ]   $t" -ForegroundColor Green }
function Info($t) { Write-Host "  [ .. ]   $t" }
function Warn($t) { Write-Host "  [WARN]   $t" -ForegroundColor Yellow }
function Die($t) {
    Write-Host ""
    Write-Host "  [FAIL]   $t" -ForegroundColor Red
    Write-Host "  Deployment ABORTED. Nothing was switched over." -ForegroundColor Red
    Write-Host "  The live site is untouched and still running." -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "FormBuilder deploy  -  release $stamp"

# ==================================================================
Step 1 "Preflight checks"
# ==================================================================

if (-not (Test-Path $ApiSource)) { Die "ApiSource not found: $ApiSource" }
if (-not (Test-Path $AppSource)) { Die "AppSource not found: $AppSource" }
$laravelSrc = Join-Path $ApiSource 'laravel'
if (-not (Test-Path $laravelSrc)) { Die "Not a form-builder-api checkout (no laravel\ dir): $ApiSource" }
Ok "Source folders found"

# --- PHP: the make-or-break check ---
$php = $null
foreach ($cand in @((Join-Path $XamppRoot 'php\php.exe'), 'php')) {
    $c = Get-Command $cand -ErrorAction SilentlyContinue
    if ($c) { $php = $c.Source; break }
}
if (-not $php) { Die "PHP not found. Looked in $XamppRoot\php and on PATH." }

$phpVer = (& $php -r "echo PHP_VERSION;")
$m = [regex]::Match($phpVer, '^(\d+)\.(\d+)\.(\d+)')
if (-not $m.Success) { Die "Could not read PHP version (got: $phpVer)" }
$maj = [int]$m.Groups[1].Value; $min = [int]$m.Groups[2].Value; $pat = [int]$m.Groups[3].Value
$phpOk = ($maj -gt 8) -or ($maj -eq 8 -and $min -gt 4) -or ($maj -eq 8 -and $min -eq 4 -and $pat -ge 1)
if (-not $phpOk) {
    Die @"
PHP $phpVer is TOO OLD. This application requires PHP >= 8.4.1.
         (composer.lock pins Symfony 8.1, which hard-requires 8.4.1.)

         Fix this first - either upgrade XAMPP to a PHP 8.4 build, or install
         PHP 8.4 alongside and point Apache's PHP module at it.
         Deploying is impossible until then.
"@
}
Ok "PHP $phpVer  (meets >= 8.4.1)"

foreach ($t in @('composer', 'npm')) {
    if (-not (Get-Command $t -ErrorAction SilentlyContinue)) { Die "$t is not installed or not on PATH." }
    Ok "$t found"
}

$mysql    = Join-Path $XamppRoot 'mysql\bin\mysql.exe'
$mysqldump= Join-Path $XamppRoot 'mysql\bin\mysqldump.exe'
if (-not (Test-Path $mysqldump)) { Die "mysqldump not found at $mysqldump - cannot back up. Refusing to deploy." }
Ok "mysqldump found"

# Credentials are passed via MYSQL_PWD so they never appear in the process list.
if ($DbPassword -ne '') { $env:MYSQL_PWD = $DbPassword }

$probe = & $mysql "-u$DbUser" --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DbName';" 2>&1
if ($LASTEXITCODE -ne 0) { Die "Cannot connect to database '$DbName' as '$DbUser'. $probe" }
if ([int]$probe -eq 0) { Die "Database '$DbName' has no tables (or does not exist). Refusing to deploy." }
Ok "Database '$DbName' reachable ($probe tables)"

# ==================================================================
Step 2 "Back up the live database (mandatory)"
# ==================================================================
New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
$backupFile = Join-Path $backupDir "$DbName-$stamp.sql"
Info "Dumping '$DbName' -> $backupFile"
& $mysqldump "-u$DbUser" --single-transaction --routines --events --databases $DbName --result-file="$backupFile" 2>&1 | Out-Null
if ($LASTEXITCODE -ne 0 -or -not (Test-Path $backupFile)) { Die "Database backup FAILED. Refusing to deploy." }
$sizeMB = [math]::Round((Get-Item $backupFile).Length / 1MB, 2)
if ($sizeMB -le 0) { Die "Backup file is empty. Refusing to deploy." }
Ok "Backup written ($sizeMB MB). Keep this file - it is your rollback."

# ==================================================================
Step 3 "Build the release"
# ==================================================================
New-Item -ItemType Directory -Force -Path $releaseDir | Out-Null
$relLaravel = Join-Path $releaseDir 'laravel'
Info "Copying application into $relLaravel"
# Copy-Item's -Exclude only takes effect when -Path itself contains a
# wildcard, and even then it does not reliably filter nested directories
# during -Recurse - it silently copied vendor/, node_modules/, and the
# developer's own .env (real local DB credentials) into every release.
# robocopy's /XD and /XF are the real thing. Default retry behavior
# (1,000,000 retries, 30s apart) is meant for interactive use - /R and /W
# keep an unattended run from hanging on a locked file.
robocopy $laravelSrc $relLaravel /E /XD vendor node_modules .git /XF .env .env.testing /R:2 /W:2 /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { Die "Copying application into $relLaravel failed (robocopy exit code $LASTEXITCODE)." }
Ok "Application copied"

Info "Installing PHP dependencies (production only)..."
Push-Location $relLaravel
& composer install --no-dev --optimize-autoloader --no-interaction --no-progress
if ($LASTEXITCODE -ne 0) { Pop-Location; Die "composer install failed." }
Pop-Location
Ok "PHP dependencies installed"

Info "Building the React frontend..."
Push-Location $AppSource
& npm ci --no-audit --no-fund
if ($LASTEXITCODE -ne 0) { Pop-Location; Die "npm ci failed." }
# Build straight into this release so the source tree is not polluted.
& npx vite build --outDir "$relLaravel\public\app" --emptyOutDir
if ($LASTEXITCODE -ne 0) { Pop-Location; Die "Frontend build failed." }
Pop-Location
if (-not (Test-Path "$relLaravel\public\app\index.html")) { Die "Frontend build produced no index.html." }
Ok "Frontend built into public\app"

# ==================================================================
Step 4 "Configure (.env) and apply migrations"
# ==================================================================
New-Item -ItemType Directory -Force -Path $sharedDir | Out-Null
$sharedEnv = Join-Path $sharedDir '.env'

if (-not (Test-Path $sharedEnv)) {
    Copy-Item (Join-Path $relLaravel '.env.production.example') $sharedEnv
    Die @"
Created a NEW .env at: $sharedEnv
         It still has PLACEHOLDER values (APP_URL, DB and MAIL credentials).
         Edit it now with the real production values, then re-run this script.
         Nothing was activated - the release folder $releaseDir was built but
         is not live, and your Step 2 backup is already safe on disk.
"@
}
Copy-Item $sharedEnv (Join-Path $relLaravel '.env') -Force

# APP_KEY must be stable across releases, or existing sessions/encrypted data break.
$envText = Get-Content (Join-Path $relLaravel '.env') -Raw
if ($envText -match '(?m)^APP_KEY=\s*$') {
    Info "No APP_KEY yet - generating one (without it, every request 500s)"
    Push-Location $relLaravel
    & $php artisan key:generate --force | Out-Null
    Pop-Location
    Copy-Item (Join-Path $relLaravel '.env') $sharedEnv -Force   # persist it
    Ok "APP_KEY generated and saved to shared .env"
} else {
    Ok "APP_KEY present (reused from shared .env)"
}

# artisan migrate is only safe to run unattended once the target database is
# already Laravel-tracked (has a 'migrations' table). On a database that
# predates this migration - the live, pre-cutover production database - the
# baseline migration's unconditional CREATE TABLEs would collide with tables
# that already exist. Migrating that database in place is a deliberate,
# one-time MANUAL step (see docs/DEPLOYMENT.md's "existing production
# database" section) - this script does not do it automatically.
Info "Checking migration tracking state..."
$migrationsTableCount = & $mysql "-u$DbUser" --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DbName' AND TABLE_NAME='migrations';"
if ([int]$migrationsTableCount -eq 0) {
    Die @"
Database '$DbName' has no 'migrations' table yet.
         This looks like the pre-Laravel production database, not yet cut
         over to Laravel-tracked migrations. Running 'artisan migrate --force'
         here would either fail (CREATE TABLE colliding with tables that
         already exist) or - if the tables happened not to collide - would
         not reflect a verified baseline.

         Cutting an existing live database over to Laravel migration tracking
         is a deliberate, ONE-TIME, MANUAL step, not something this script
         does for you. See "For the existing production database (one time,
         at cutover)" in form-builder-app/docs/DEPLOYMENT.md for the exact
         procedure (php artisan migrate:install, then recording the baseline
         migration as already applied - only after confirming the live
         schema genuinely matches what the migration would have created).

         Do that once, by hand, then re-run this script: it will detect the
         'migrations' table and proceed normally on this and all future
         deploys. (Your Step 2 backup is already safe on disk.)
"@
}
Ok "'migrations' tracking table present"

# The table existing is not enough on its own: DEPLOYMENT.md's manual cutover
# procedure has the operator do this in two separate steps (1. create the
# table via `migrate:install`, 2. a manual INSERT for the baseline row). If an
# operator does step 1 but stops before step 2, the table exists but nothing
# in it says the baseline schema was actually verified against the live
# database - proceeding here would let artisan migrate run CREATE TABLE users
# straight into a collision with the tables that are already there.
$baselineRowCount = & $mysql "-u$DbUser" --batch --skip-column-names -e "SELECT COUNT(*) FROM ``$DbName``.migrations WHERE migration='2026_07_14_000000_create_form_builder_schema';"
if ([int]$baselineRowCount -eq 0) {
    Die @"
Database '$DbName' has a 'migrations' table, but the baseline row
         ('2026_07_14_000000_create_form_builder_schema') is not recorded in
         it. The migrations table existing is not enough by itself - it only
         means 'php artisan migrate:install' was run.

         You likely completed step 1 of the cutover (migrate:install) but not
         step 2 (the manual INSERT recording the baseline migration as
         already applied). Running 'artisan migrate --force' in this state
         would try to CREATE TABLE users and collide with the tables that
         already exist on this live database.

         See "For the existing production database (one time, at cutover)"
         in form-builder-app/docs/DEPLOYMENT.md - cutover step 3 is the
         INSERT you still need to run. Do that, then re-run this script.
         (Your Step 2 backup is already safe on disk.)
"@
}
Ok "Baseline migration row present - safe to run artisan migrate"

# Sanity-check the release's DB_DATABASE matches -DbName. The backup and the
# migrations-table check above ran against -DbName/-DbUser/-DbPassword directly
# via mysql; artisan migrate below reads its own credentials from this .env.
# The two are meant to describe the same database - if .env was hand-edited
# to point somewhere else, they'd silently disagree.
if ($envText -match '(?m)^DB_DATABASE=(.*)$' -and $Matches[1].Trim() -ne $DbName) {
    Warn "DB_DATABASE in .env ('$($Matches[1].Trim())') does not match -DbName ('$DbName')."
    Warn "The backup and migration-tracking check above ran against '$DbName'; artisan will use '$($Matches[1].Trim())'."
}

Info "Applying database migrations..."
Push-Location $relLaravel
& $php artisan migrate --force
if ($LASTEXITCODE -ne 0) {
    Pop-Location
    Die @"
artisan migrate failed. Before restoring anything, check:
         1. Does the 'migrations' table's recorded state actually match what
            is in the database (a previous deploy left things half-applied)?
         2. Are the DB credentials in $sharedEnv correct - does DB_DATABASE
            really point at '$DbName'?
         If neither explains it, restore from $backupFile.
"@
}
Pop-Location
Ok "Migrations applied (tracked in the migrations table)"

Push-Location $relLaravel
& $php artisan config:cache | Out-Null
& $php artisan view:cache   | Out-Null
# NOTE: `route:cache` is deliberately NOT run. routes/web.php uses a closure
# for the React catch-all, and Laravel cannot serialize closure routes.
Pop-Location
Ok "Config and view caches built"

# --- Uploaded files must SURVIVE deploys --------------------------------
# The app writes uploads to public_path('uploads') - i.e. INSIDE the release.
# Left alone, every deploy would start with an empty folder and the uploaded
# banner would vanish. So keep the real folder in shared\ and link it in.
$sharedUploads  = Join-Path $sharedDir 'uploads'
$releaseUploads = Join-Path $relLaravel 'public\uploads'
New-Item -ItemType Directory -Force -Path $sharedUploads | Out-Null

# First deploy: seed shared\uploads from whatever the release shipped with.
if (Test-Path $releaseUploads) {
    Get-ChildItem $releaseUploads -File -ErrorAction SilentlyContinue | ForEach-Object {
        $target = Join-Path $sharedUploads $_.Name
        if (-not (Test-Path $target)) { Copy-Item $_.FullName $target }
    }
    Remove-Item $releaseUploads -Recurse -Force
}
cmd /c mklink /J "$releaseUploads" "$sharedUploads" | Out-Null
if (-not (Test-Path $releaseUploads)) { Die "Could not link the shared uploads folder." }
$uploadCount = (Get-ChildItem $sharedUploads -File -ErrorAction SilentlyContinue | Measure-Object).Count
Ok "Uploads linked to shared folder ($uploadCount file(s) preserved across deploys)"

# ==================================================================
Step 5 "Smoke test (before switching any traffic)"
# ==================================================================
Info "Booting the release on port 8899 to verify it responds..."
$proc = Start-Process -FilePath $php -ArgumentList @('artisan','serve','--port=8899','--host=127.0.0.1') `
                      -WorkingDirectory $relLaravel -PassThru -WindowStyle Hidden
Start-Sleep -Seconds 5
$healthy = $false
try {
    $r = Invoke-WebRequest -Uri 'http://127.0.0.1:8899/_fb_laravel_health' -UseBasicParsing -TimeoutSec 10
    if ($r.StatusCode -eq 200) { $healthy = $true }
} catch { }
if ($proc -and -not $proc.HasExited) { Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue }

if (-not $healthy) {
    Die @"
The new release did not return a healthy response.
         Most likely causes: .env is incomplete, or APP_KEY is missing.
         Check: $sharedDir\.env
         NOTHING was switched. The live site is still running the old app.
"@
}
Ok "Health check passed - the new release boots and responds"

# ==================================================================
Step 6 "Activate this release"
# ==================================================================
$previous = $null
if (Test-Path $currentLink) {
    $previous = (Get-Item $currentLink).Target
    cmd /c rmdir "$currentLink" | Out-Null
}
cmd /c mklink /J "$currentLink" "$releaseDir" | Out-Null
if (-not (Test-Path $currentLink)) { Die "Could not create the 'current' link." }
Ok "'current' now points at release $stamp"

# ==================================================================
Write-Host ""
Write-Host ("=" * 66) -ForegroundColor Green
Write-Host "  DEPLOY COMPLETE - release $stamp" -ForegroundColor Green
Write-Host ("=" * 66) -ForegroundColor Green
Write-Host ""
Write-Host "  Backup taken   : $backupFile"
Write-Host "  Release folder : $releaseDir"
Write-Host "  Shared config  : $sharedEnv"
Write-Host ""
Write-Host "  --- FIRST DEPLOY ONLY: point Apache here, then restart it ---" -ForegroundColor Yellow
Write-Host ""
Write-Host "  In $XamppRoot\apache\conf\extra\httpd-vhosts.conf add:"
Write-Host ""
Write-Host "      <VirtualHost *:80>"
Write-Host "          DocumentRoot `"$currentLink\laravel\public`""
Write-Host "          <Directory `"$currentLink\laravel\public`">"
Write-Host "              AllowOverride All"
Write-Host "              Require all granted"
Write-Host "          </Directory>"
Write-Host "      </VirtualHost>"
Write-Host ""
Write-Host "  Then restart Apache from the XAMPP Control Panel."
Write-Host "  (Later deploys need NO Apache changes - the link just moves.)"
Write-Host ""
Write-Host "  --- IF SOMETHING IS WRONG: ROLL BACK ---" -ForegroundColor Yellow
if ($previous) {
    Write-Host ""
    Write-Host "      rmdir `"$currentLink`""
    Write-Host "      mklink /J `"$currentLink`" `"$previous`""
    Write-Host "      (then restart Apache)"
} else {
    Write-Host ""
    Write-Host "      This was the first deploy. To go back, simply point Apache's"
    Write-Host "      DocumentRoot at the old app in htdocs again and restart it."
}
Write-Host ""
Write-Host "      Restore the database if needed:"
Write-Host "      $XamppRoot\mysql\bin\mysql.exe -u$DbUser < `"$backupFile`""
Write-Host ""
