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
      * Never writes into htdocs and never drops/alters your data.
      * Database schema migrations are NOT applied unless you explicitly
        pass -ApplyMigrations.

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

    [string]$XamppRoot  = 'C:\xampp',

    # Apply the numbered SQL migrations. OFF by default: applying them twice
    # can error, and we cannot tell what production already has.
    [switch]$ApplyMigrations
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
Step 3 "Check schema / migration state"
# ==================================================================
$expected = @('audit_logs', 'notifications', 'password_reset_codes')
$missing = @()
foreach ($t in $expected) {
    $n = & $mysql "-u$DbUser" --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DbName' AND TABLE_NAME='$t';"
    if ([int]$n -eq 0) { $missing += $t }
}
if ($missing.Count -eq 0) {
    Ok "All expected tables present - schema looks current"
} else {
    Warn ("Missing tables: " + ($missing -join ', '))
    if (-not $ApplyMigrations) {
        Die @"
The live database is missing tables the new app needs.
         Re-run with -ApplyMigrations to apply the numbered SQL migrations
         from $ApiSource\migrations in order.
         (Your backup from Step 2 is already safe on disk.)
"@
    }
    Info "Applying numbered migrations from $ApiSource\migrations ..."
    Get-ChildItem (Join-Path $ApiSource 'migrations') -Filter '*.sql' | Sort-Object Name | ForEach-Object {
        Info "  applying $($_.Name)"
        Get-Content $_.FullName -Raw | & $mysql "-u$DbUser" $DbName
        if ($LASTEXITCODE -ne 0) { Die "Migration $($_.Name) failed. Restore from $backupFile." }
    }
    Ok "Migrations applied"
}

# ==================================================================
Step 4 "Build the release"
# ==================================================================
New-Item -ItemType Directory -Force -Path $releaseDir | Out-Null
$relLaravel = Join-Path $releaseDir 'laravel'
Info "Copying application into $relLaravel"
Copy-Item $laravelSrc -Destination $relLaravel -Recurse -Force -Exclude @('node_modules', 'vendor')
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
Step 5 "Configure (.env) - shared across releases"
# ==================================================================
New-Item -ItemType Directory -Force -Path $sharedDir | Out-Null
$sharedEnv = Join-Path $sharedDir '.env'

if (-not (Test-Path $sharedEnv)) {
    Copy-Item (Join-Path $relLaravel '.env.production.example') $sharedEnv
    Warn "Created a NEW .env at: $sharedEnv"
    Warn "You MUST edit it (APP_URL, DB and MAIL credentials) before going live."
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

Push-Location $relLaravel
& $php artisan config:cache | Out-Null
& $php artisan view:cache   | Out-Null
# NOTE: `route:cache` is deliberately NOT run. routes/web.php uses a closure
# for the React catch-all, and Laravel cannot serialize closure routes.
Pop-Location
Ok "Config and view caches built"

# ==================================================================
Step 6 "Smoke test (before switching any traffic)"
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
Step 7 "Activate this release"
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
