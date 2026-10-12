@echo off
REM ---------------------------------------------------------------------------
REM Drains the email outbox, every 5 minutes.
REM
REM Installed by tools/install_services.bat and run by Task Scheduler as SYSTEM,
REM so every path here is absolute -- SYSTEM has a different home folder and
REM working directory than you do, and a path built from %~dp0 or the current
REM directory would resolve somewhere else. For the same reason PHP is named in
REM full: a .bat has no PHP_BINARY, and SYSTEM's PATH does not include XAMPP.
REM
REM An offline PC delays mail rather than losing it: rows wait in email_outbox
REM until the next run that can reach Gmail.
REM
REM An overlapping run prints "already in progress; skipping" and exits 0, which
REM is normal. A run stopped for missing credentials exits 2 and shows as failed
REM in Task Scheduler's history -- see EMAIL_SETUP.md.
REM
REM Appends to qa-email.log next to the project (*.log is gitignored).
REM ---------------------------------------------------------------------------

setlocal
set "PROJECT=D:\Users\Gab\Desktop\Quality Assurance\qualityassurancesystem---php-system"
set "LOG=%PROJECT%\qa-email.log"

REM delims= keeps the whole timestamp: without it the space before the
REM time splits the value and only the date survives.
for /f "delims=" %%i in ('powershell -NoProfile -Command "Get-Date -Format \"yyyy-MM-dd HH:mm:ss\""') do set "NOW=%%i"

echo [%NOW%] email_worker run >> "%LOG%"
"C:\xampp\php\php.exe" "%PROJECT%\email_worker.php" run >> "%LOG%" 2>&1
set "RC=%errorlevel%"

REM Keep the log from growing without limit: trim to the last 500 lines once
REM it passes 1000.
for /f %%n in ('powershell -NoProfile -Command "if (Test-Path '%LOG%') { (Get-Content '%LOG%' | Measure-Object -Line).Lines } else { 0 }"') do set "LINES=%%n"
if %LINES% GTR 1000 powershell -NoProfile -Command "Get-Content '%LOG%' -Tail 500 | Set-Content '%LOG%.tmp'; Move-Item -Force '%LOG%.tmp' '%LOG%'"

REM Hand PHP's exit code to Task Scheduler, so a halted run is not recorded as
REM a success.
endlocal & exit /b %RC%
