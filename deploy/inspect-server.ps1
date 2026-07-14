<#
    FormBuilder - Server Inspection (READ-ONLY)
    ------------------------------------------------------------------
    Run this ON the XAMPP server to find out what is actually installed
    before any deployment is attempted.

    THIS SCRIPT IS SAFE. It only READS and PRINTS.
    It does not install, modify, move, delete, or write anything.
    It does not change the database - it only counts rows.

    HOW TO RUN
      1. Copy this file to the server (e.g. C:\inspect-server.ps1)
      2. Open PowerShell
      3. Run:   powershell -ExecutionPolicy Bypass -File C:\inspect-server.ps1
      4. Copy ALL the output and send it back.

    Admin rights are NOT required.
#>

param(
    # MySQL/MariaDB root password. XAMPP's default is empty, but this server
    # has one set. It is only held in memory - never written or logged.
    [string]$DbPassword = '',
    [string]$DbUser     = 'root'
)

$ErrorActionPreference = 'Continue'

function Write-Head($text) {
    Write-Output ""
    Write-Output ("=" * 62)
    Write-Output "  $text"
    Write-Output ("=" * 62)
}
function Write-Item($label, $value) {
    Write-Output ("  {0,-26} {1}" -f ($label + ':'), $value)
}

$findings = @{}

Write-Output ""
Write-Output "FormBuilder server inspection (read-only)"
Write-Output ("Run at: " + (Get-Date))

# ------------------------------------------------------------------
Write-Head "1. Machine"
# ------------------------------------------------------------------
try {
    $os = Get-CimInstance Win32_OperatingSystem -ErrorAction Stop
    Write-Item "OS" $os.Caption
    Write-Item "Version" $os.Version
} catch {
    Write-Item "OS" "(could not read)"
}
Write-Item "Hostname" $env:COMPUTERNAME
Write-Item "PowerShell" $PSVersionTable.PSVersion.ToString()
try {
    $c = Get-PSDrive C -ErrorAction Stop
    $freeGB = [math]::Round($c.Free / 1GB, 1)
    Write-Item "Free disk (C:)" "$freeGB GB"
} catch { }

# ------------------------------------------------------------------
Write-Head "2. XAMPP"
# ------------------------------------------------------------------
$xamppCandidates = @('C:\xampp', 'D:\xampp', 'C:\XAMPP', "$env:SystemDrive\xampp")
$xampp = $null
foreach ($p in $xamppCandidates) {
    if (Test-Path $p) { $xampp = $p; break }
}
if (-not $xampp) {
    Write-Item "XAMPP" "NOT FOUND in common locations"
    Write-Output "  -> Searching drives (this may take a moment)..."
    $hit = Get-ChildItem -Path C:\ -Filter 'xampp' -Directory -Depth 2 -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($hit) { $xampp = $hit.FullName }
}
if ($xampp) {
    Write-Item "XAMPP found at" $xampp
    $findings.xampp = $xampp
} else {
    Write-Item "XAMPP" "NOT FOUND"
}

# ------------------------------------------------------------------
Write-Head "3. PHP  (must be 8.4.1 or newer)"
# ------------------------------------------------------------------
$phpExes = @()
if ($xampp -and (Test-Path "$xampp\php\php.exe")) { $phpExes += "$xampp\php\php.exe" }
$onPath = Get-Command php -ErrorAction SilentlyContinue
if ($onPath) { $phpExes += $onPath.Source }
$phpExes = $phpExes | Select-Object -Unique

if ($phpExes.Count -eq 0) {
    Write-Item "PHP" "NOT FOUND"
} else {
    foreach ($php in $phpExes) {
        Write-Output ""
        Write-Item "PHP executable" $php
        try {
            # -n skips php.ini. Without it, broken extension warnings are printed
            # BEFORE the version and turn the result into an array (the bug that
            # made this print "System.Object[]" last time).
            $verRaw  = (& $php -n -r "echo PHP_VERSION;" 2>$null)
            $verLine = (($verRaw | Out-String).Trim() -split "`r?`n" | Where-Object { $_ -match '^\d+\.\d+\.\d+' } | Select-Object -First 1)
            if (-not $verLine) { $verLine = ($verRaw | Out-String).Trim() }
            Write-Item "PHP version" $verLine
            $m = [regex]::Match($verLine, '^(\d+)\.(\d+)\.(\d+)')
            if ($m.Success) {
                $maj = [int]$m.Groups[1].Value
                $min = [int]$m.Groups[2].Value
                $pat = [int]$m.Groups[3].Value
                $ok = ($maj -gt 8) -or ($maj -eq 8 -and $min -gt 4) -or ($maj -eq 8 -and $min -eq 4 -and $pat -ge 1)
                if ($ok) {
                    Write-Item "VERDICT" "OK - meets the 8.4.1 requirement"
                    $findings.phpOk = $true
                } else {
                    Write-Item "VERDICT" "TOO OLD - app requires PHP >= 8.4.1"
                    $findings.phpOk = $false
                }
            }
            $exts = (& $php -r "echo implode(',', get_loaded_extensions());" 2>$null)
            foreach ($need in @('pdo_mysql','mbstring','openssl','curl','fileinfo','zip')) {
                if ($exts -match $need) {
                    Write-Item "  ext $need" "present"
                } else {
                    Write-Item "  ext $need" "MISSING"
                }
            }
        } catch {
            Write-Item "PHP version" "(failed to run php.exe)"
        }
    }
}

# ------------------------------------------------------------------
Write-Head "4. Apache (web server)"
# ------------------------------------------------------------------
$apacheProc = Get-Process httpd -ErrorAction SilentlyContinue
if ($apacheProc) {
    Write-Item "Apache running" ("YES (" + $apacheProc.Count + " process(es))")
} else {
    Write-Item "Apache running" "no (not currently running)"
}
if ($xampp) {
    $httpdConf = "$xampp\apache\conf\httpd.conf"
    $vhostConf = "$xampp\apache\conf\extra\httpd-vhosts.conf"
    if (Test-Path $httpdConf) {
        Write-Item "httpd.conf" $httpdConf
        $docRoot = Select-String -Path $httpdConf -Pattern '^\s*DocumentRoot\s+"?([^"]+)"?' -ErrorAction SilentlyContinue |
                   Select-Object -First 1
        if ($docRoot) { Write-Item "DocumentRoot" $docRoot.Matches[0].Groups[1].Value }
        $listen = Select-String -Path $httpdConf -Pattern '^\s*Listen\s+(\S+)' -ErrorAction SilentlyContinue |
                  ForEach-Object { $_.Matches[0].Groups[1].Value }
        if ($listen) { Write-Item "Listen (ports)" ($listen -join ', ') }
    }
    if (Test-Path $vhostConf) {
        $vhosts = Select-String -Path $vhostConf -Pattern '^\s*(<VirtualHost|ServerName|DocumentRoot)' -ErrorAction SilentlyContinue
        if ($vhosts) {
            Write-Output "  Virtual hosts configured:"
            $vhosts | ForEach-Object { Write-Output ("    " + $_.Line.Trim()) }
        } else {
            Write-Item "Virtual hosts" "none configured"
        }
    }
}

# ------------------------------------------------------------------
Write-Head "5. htdocs  (where the LIVE app probably lives)"
# ------------------------------------------------------------------
if ($xampp -and (Test-Path "$xampp\htdocs")) {
    $htdocs = "$xampp\htdocs"
    Write-Item "htdocs path" $htdocs
    Write-Output "  Top-level contents:"
    Get-ChildItem $htdocs -ErrorAction SilentlyContinue |
        Select-Object -First 40 |
        ForEach-Object {
            $kind = "file"
            if ($_.PSIsContainer) { $kind = "DIR " }
            Write-Output ("    [{0}] {1}" -f $kind, $_.Name)
        }
    $phpCount = (Get-ChildItem $htdocs -Filter *.php -Recurse -ErrorAction SilentlyContinue | Measure-Object).Count
    Write-Item "PHP files under htdocs" $phpCount
} else {
    Write-Item "htdocs" "NOT FOUND"
}

# ------------------------------------------------------------------
Write-Head "6. Database (MariaDB / MySQL)"
# ------------------------------------------------------------------
$mysqlProc = Get-Process mysqld -ErrorAction SilentlyContinue
if ($mysqlProc) {
    Write-Item "MySQL running" "YES"
} else {
    Write-Item "MySQL running" "no (not currently running)"
}

$mysqlExe = $null
if ($xampp -and (Test-Path "$xampp\mysql\bin\mysql.exe")) { $mysqlExe = "$xampp\mysql\bin\mysql.exe" }

if (-not $mysqlExe) {
    Write-Item "mysql client" "NOT FOUND - cannot inspect databases"
} else {
    Write-Item "mysql client" $mysqlExe

    # Password is passed via MYSQL_PWD so it never appears in the process list.
    if ($DbPassword -ne '') { $env:MYSQL_PWD = $DbPassword }
    function Invoke-Sql($sql) {
        $out = & $mysqlExe "-u$DbUser" "--batch" "--skip-column-names" "-e" $sql 2>&1
        return $out
    }

    $ver = Invoke-Sql "SELECT VERSION();"
    if ($LASTEXITCODE -ne 0 -or ($ver -match 'Access denied')) {
        Write-Item "DB connect" "FAILED as '$DbUser'"
        Write-Output "  -> Re-run passing the password:  -DbPassword '<password>'"
    } else {
        Write-Item "DB server version" ($ver | Select-Object -First 1)

        Write-Output ""
        Write-Output "  Databases on this server:"
        $dbs = Invoke-Sql "SHOW DATABASES;"
        $dbs | ForEach-Object { Write-Output ("    - " + $_) }

        $hasFb = $dbs -contains 'form_builder'
        Write-Output ""
        if (-not $hasFb) {
            Write-Item "form_builder DB" "NOT FOUND"
        } else {
            Write-Item "form_builder DB" "FOUND"
            Write-Output ""
            Write-Output "  Tables in form_builder (and row counts):"
            $tables = Invoke-Sql "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='form_builder' ORDER BY TABLE_NAME;"
            foreach ($t in $tables) {
                if ([string]::IsNullOrWhiteSpace($t)) { continue }
                $cnt = Invoke-Sql "SELECT COUNT(*) FROM ``form_builder``.``$t``;"
                Write-Output ("    {0,-28} {1} rows" -f $t, ($cnt | Select-Object -First 1))
            }

            # These tables only exist if later migrations were applied.
            Write-Output ""
            Write-Output "  Migration state check (later migrations):"
            foreach ($t in @('audit_logs','notifications','password_reset_codes')) {
                if ($tables -contains $t) {
                    Write-Output ("    {0,-28} present" -f $t)
                } else {
                    Write-Output ("    {0,-28} MISSING (migration not applied)" -f $t)
                }
            }
        }
    }
}

# ------------------------------------------------------------------
Write-Head "7. Build tooling (needed to deploy)"
# ------------------------------------------------------------------
foreach ($tool in @('composer','node','npm','git')) {
    $cmd = Get-Command $tool -ErrorAction SilentlyContinue
    if ($cmd) {
        $v = ""
        try { $v = (& $tool --version 2>$null | Select-Object -First 1) } catch { }
        Write-Item $tool ("FOUND  " + $v)
    } else {
        Write-Item $tool "not installed"
    }
}

# ------------------------------------------------------------------
Write-Head "SUMMARY"
# ------------------------------------------------------------------
if ($findings.ContainsKey('phpOk')) {
    if ($findings.phpOk) {
        Write-Output "  PHP:  OK (>= 8.4.1)"
    } else {
        Write-Output "  PHP:  TOO OLD  <-- BLOCKER. The app needs PHP >= 8.4.1."
        Write-Output "        XAMPP's bundled PHP must be upgraded, or PHP 8.4"
        Write-Output "        installed alongside, before this app can run."
    }
} else {
    Write-Output "  PHP:  not detected"
}
Write-Output ""
Write-Output "  Nothing was changed on this machine."
Write-Output "  Please copy ALL output above and send it back."
Write-Output ""
