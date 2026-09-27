@echo off
REM ---------------------------------------------------------------------------
REM Restart anything that has stopped, every 5 minutes.
REM
REM Installed by tools/install_services.bat and run by Task Scheduler as SYSTEM,
REM so every path here is absolute -- SYSTEM has a different home folder and
REM working directory than you do.
REM
REM Services usually restart themselves after a crash, but they do not come back
REM if somebody stops them by hand, which is what took the site down on
REM 2026-09-22. This also catches Apache answering but failing, by asking it for
REM a real page rather than trusting the service state.
REM
REM Writes qa-health.log next to the project (*.log is gitignored).
REM ---------------------------------------------------------------------------

setlocal
set "LOG=D:\Users\Gab\Desktop\Quality Assurance\qualityassurancesystem---php-system\qa-health.log"
set "SITE=http://127.0.0.1/"
set "HOSTHDR=sbc-quality-assurance.com"

REM delims= keeps the whole timestamp: without it the space before the
REM time splits the value and only the date survives.
for /f "delims=" %%i in ('powershell -NoProfile -Command "Get-Date -Format \"yyyy-MM-dd HH:mm:ss\""') do set "NOW=%%i"

REM --- services ---
for %%S in (mysql Apache2.4 cloudflared) do (
    sc query %%S | find "RUNNING" >nul 2>&1
    if errorlevel 1 (
        sc start %%S >nul 2>&1
        echo [%NOW%] %%S was not running - start issued >> "%LOG%"
    )
)

REM --- does the site actually answer? ---
REM The Host header matters: Apache picks the QA vhost by name, and without it
REM the request lands on the localhost vhost instead.
for /f %%c in ('powershell -NoProfile -Command "try { (Invoke-WebRequest -Uri '%SITE%' -Headers @{Host='%HOSTHDR%'} -UseBasicParsing -TimeoutSec 15).StatusCode } catch { 0 }"') do set "CODE=%%c"

if not "%CODE%"=="200" (
    echo [%NOW%] site returned %CODE% - restarting Apache >> "%LOG%"
    sc stop Apache2.4 >nul 2>&1
    timeout /t 5 /nobreak >nul
    sc start Apache2.4 >nul 2>&1
)

REM Keep the log from growing without limit: trim to the last 500 lines daily.
for /f %%n in ('powershell -NoProfile -Command "if (Test-Path '%LOG%') { (Get-Content '%LOG%' | Measure-Object -Line).Lines } else { 0 }"') do set "LINES=%%n"
if %LINES% GTR 1000 powershell -NoProfile -Command "Get-Content '%LOG%' -Tail 500 | Set-Content '%LOG%.tmp'; Move-Item -Force '%LOG%.tmp' '%LOG%'"

endlocal
