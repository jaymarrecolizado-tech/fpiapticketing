@echo off
REM Automated backup script for Windows Task Scheduler
REM Usage: automated_backup.bat [type]
REM Types: full, database, filesystem

if "%1"=="" (
    set BACKUP_TYPE=full
) else (
    set BACKUP_TYPE=%1
)

REM Change to the scripts directory
cd /d "%~dp0"

REM Run the PHP backup script
php automated_backup.php %BACKUP_TYPE%

REM Log the result
echo Backup completed at %DATE% %TIME% >> backup_log.txt

pause