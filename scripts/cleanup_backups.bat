@echo off
REM Cleanup old backup files script for Windows

REM Change to the scripts directory
cd /d "%~dp0"

REM Run the PHP cleanup script
php cleanup_backups.php

REM Log the result
echo Cleanup completed at %DATE% %TIME% >> cleanup_log.txt

pause