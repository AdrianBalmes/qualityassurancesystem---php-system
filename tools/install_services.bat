@echo off
REM ---------------------------------------------------------------------------
REM Install Apache, MySQL and the Cloudflare tunnel as Windows services, and
REM register the health check.
REM
REM Why: the site went dark for most of 2026-09-22 because Apache and MySQL
REM were stopped while the tunnel kept running, so Cloudflare had nothing to
REM forward to and served its "web server is down" page. Nothing was installed
REM as a service, so nothing restarted itself -- and nothing survives a reboot.
REM
REM RUN THIS FROM AN ADMINISTRATOR TERMINAL. Right-click PowerShell or Command
REM Prompt and choose "Run as administrator", then run this file.
REM ---------------------------------------------------------------------------

net session >nul 2>&1
if errorlevel 1 (
    echo This script must be run from an Administrator terminal.
    echo Right-click PowerShell or Command Prompt and choose "Run as administrator".
    exit /b 1
)

set "XAMPP=C:\xampp"
set "CLOUDFLARED=C:\Program Files (x86)\cloudflared\cloudflared.exe"
set "CFCONFIG=%USERPROFILE%\.cloudflared\config.yml"
set "PROJECT=%~dp0.."

echo.
echo === Stopping anything already running by hand ===

REM MySQL must be shut down cleanly. Killing it mid-write corrupted the data
REM directory once already (2026-09-15) and cost a rebuild from backup.
"%XAMPP%\mysql\bin\mysqladmin.exe" -u root shutdown >nul 2>&1
if errorlevel 1 (echo   MySQL was not running, or is already a service) else (echo   MySQL shut down cleanly)

REM Apache holds no state, so stopping it abruptly is safe.
taskkill /F /IM httpd.exe >nul 2>&1
if errorlevel 1 (echo   Apache was not running) else (echo   Apache stopped)

taskkill /F /IM cloudflared.exe >nul 2>&1
if errorlevel 1 (echo   cloudflared was not running) else (echo   cloudflared stopped)

echo.
echo === Installing services ===

"%XAMPP%\apache\bin\httpd.exe" -k install -n "Apache2.4" >nul 2>&1
if errorlevel 1 (echo   Apache2.4: already installed) else (echo   Apache2.4: installed)

"%XAMPP%\mysql\bin\mysqld.exe" --install mysql --defaults-file="%XAMPP%\mysql\bin\my.ini" >nul 2>&1
if errorlevel 1 (echo   mysql: already installed) else (echo   mysql: installed)

if not exist "%CFCONFIG%" (
    echo   cloudflared: SKIPPED -- no config at "%CFCONFIG%"
) else (
    "%CLOUDFLARED%" --config "%CFCONFIG%" service install >nul 2>&1
    if errorlevel 1 (echo   cloudflared: already installed) else (echo   cloudflared: installed)
)

echo.
echo === Setting them to start with Windows ===
for %%S in (Apache2.4 mysql cloudflared) do (
    sc config %%S start= auto >nul 2>&1
    if errorlevel 1 (echo   %%S: not installed) else (echo   %%S: automatic)
)

echo.
echo === Starting them now ===
REM MySQL first: Apache serves pages that need it immediately.
for %%S in (mysql Apache2.4 cloudflared) do (
    sc start %%S >nul 2>&1
    if errorlevel 1 (echo   %%S: already running, or failed -- check "sc query %%S") else (echo   %%S: started)
)

echo.
echo === Registering the health check (every 5 minutes) ===
schtasks /Create /TN "QA System - Health Check" /TR "\"%PROJECT%\tools\health_check.bat\"" /SC MINUTE /MO 5 /RU SYSTEM /RL HIGHEST /F >nul 2>&1
if errorlevel 1 (echo   could not register the task) else (echo   registered: "QA System - Health Check")

echo.
echo Done. Check with:  sc query Apache2.4 ^& sc query mysql ^& sc query cloudflared
echo The real proof is a reboot: the site should answer without anyone signing in.
