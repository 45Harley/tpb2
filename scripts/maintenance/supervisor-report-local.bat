@echo off
setlocal

:: ============================================================
:: TPB Supervisor Daily Report
:: Uploads report script to server, runs it, saves output locally
:: Schedule: daily 8 AM (after all pipelines complete)
:: ============================================================

set LOGDIR=c:\tpb2\scripts\maintenance\logs
set LOGFILE=%LOGDIR%\supervisor-report.log
set REPORT=%LOGDIR%\supervisor-report-latest.txt
set SSH=ssh sandge5@ecngx308.inmotionhosting.com -p 2222
set SCP=scp -P 2222
set REMOTE_TMP=~/tmp_supervisor

if not exist "%LOGDIR%" mkdir "%LOGDIR%"

echo ============================================================ >> "%LOGFILE%"
echo START: %date% %time% >> "%LOGFILE%"

:: Upload and run report
%SCP% "c:\tpb2\scripts\maintenance\supervisor-report.php" sandge5@ecngx308.inmotionhosting.com:%REMOTE_TMP%.php >> "%LOGFILE%" 2>&1
if %errorlevel% neq 0 (
    echo [%time%] ERROR: SCP failed >> "%LOGFILE%"
    goto :done
)

%SSH% "/usr/local/bin/ea-php84 %REMOTE_TMP%.php; rm %REMOTE_TMP%.php" > "%REPORT%" 2>> "%LOGFILE%"
if %errorlevel% neq 0 (
    echo [%time%] ERROR: SSH failed >> "%LOGFILE%"
    goto :done
)

:: Append to log and show
type "%REPORT%" >> "%LOGFILE%"
echo [%time%] Report saved to %REPORT% >> "%LOGFILE%"

:done
echo END: %date% %time% >> "%LOGFILE%"
echo ============================================================ >> "%LOGFILE%"
endlocal
