# Starts the Form Builder on this PC: XAMPP MySQL and the Form Builder API
# (127.0.0.1:8001). Safe to run repeatedly -- anything already listening is left
# alone. Launched at logon by the Startup-folder shortcut (FormBuilder-services.vbs).
#
# The AGAP kiosk no longer uses the Form Builder (its forms are native now), so this
# only matters to whoever still uses the Form Builder itself.
#
# MySQL listens on 127.0.0.1 only (bind-address in D:\xampp\mysql\bin\my.ini), so
# nothing else on the network can reach the form responses -- root has no password.

$mysqlExe = 'D:\xampp\mysql\bin\mysqld.exe'
$mysqlIni = 'D:\xampp\mysql\bin\my.ini'
$apiDir   = (Resolve-Path (Join-Path $PSScriptRoot '..\laravel')).Path

function Test-PortListening([int]$Port) {
    [bool](Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)
}

if (-not (Test-PortListening 3306)) {
    Start-Process -FilePath $mysqlExe -ArgumentList "--defaults-file=$mysqlIni", '--standalone' -WindowStyle Hidden
}

# The API needs the database up before it can answer anything useful.
$deadline = (Get-Date).AddSeconds(60)
while (-not (Test-PortListening 3306) -and (Get-Date) -lt $deadline) {
    Start-Sleep -Seconds 1
}

if (-not (Test-PortListening 8001)) {
    Start-Process -FilePath 'php' -ArgumentList 'artisan', 'serve', '--host=127.0.0.1', '--port=8001' -WorkingDirectory $apiDir -WindowStyle Hidden
}
