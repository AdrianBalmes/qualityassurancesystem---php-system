@echo off
REM Daily database backup for the QA system.
REM
REM uploads/ already reaches OneDrive through the app's local sync, but the
REM database does not -- and the database is where every recommendation,
REM account and review lives. Without this a disk failure loses all of it.
REM
REM Writes ems_db-YYYY-MM-DD.sql into the OneDrive folder (so it syncs offsite)
REM and keeps the last 7 days. Run daily from Task Scheduler.
REM
REM The dump contains password hashes and email addresses: keep the folder
REM private and never commit it.

setlocal
set "MYSQLDUMP=C:\xampp\mysql\bin\mysqldump.exe"
set "DEST=%USERPROFILE%\OneDrive - St. Bridget College, Inc\QA Backups"

for /f %%i in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd"') do set "STAMP=%%i"

if not exist "%DEST%" mkdir "%DEST%"

"%MYSQLDUMP%" -u root --single-transaction --routines --triggers --events --default-character-set=utf8mb4 ems_db > "%DEST%\ems_db-%STAMP%.sql"

if errorlevel 1 (
    echo [%STAMP%] mysqldump FAILED >> "%DEST%\backup.log"
    exit /b 1
)

for %%F in ("%DEST%\ems_db-%STAMP%.sql") do echo [%STAMP%] ok, %%~zF bytes >> "%DEST%\backup.log"

REM Keep a week of history.
forfiles /p "%DEST%" /m ems_db-*.sql /d -7 /c "cmd /c del @path" 2>nul

endlocal
