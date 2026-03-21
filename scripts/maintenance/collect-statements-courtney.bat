@echo off
setlocal

:: ============================================================
:: Statement Collection: Rep. Joe Courtney (official_id=390)
:: Step 1: SSH - server PHP gathers DB context
:: Step 2: Local claude -p does web search
:: Step 3: SSH - server PHP parses + inserts into DB
:: ============================================================

set OFFICIAL_ID=390
set OFFICIAL_NAME=courtney
set LOGFILE=c:\tpb2\scripts\maintenance\logs\collect-statements-%OFFICIAL_NAME%.log
set TMPDIR=c:\tpb2\tmp
set PHP=C:\xampp\php\php.exe
set SSH=ssh sandge5@ecngx308.inmotionhosting.com -p 2222
set SCP=scp -P 2222
set REMOTE_TMP=~/tmp_stmt_%OFFICIAL_NAME%

if not exist "c:\tpb2\scripts\maintenance\logs" mkdir "c:\tpb2\scripts\maintenance\logs"
if not exist "%TMPDIR%" mkdir "%TMPDIR%"

echo ============================================================ >> "%LOGFILE%"
echo START: %date% %time% >> "%LOGFILE%"

:: --- Step 1: Upload gather script and run on server ---
echo [%time%] Step 1: Gathering DB context for %OFFICIAL_NAME% (id=%OFFICIAL_ID%)... >> "%LOGFILE%"

%SCP% "c:\tpb2\scripts\maintenance\collect-statements-step1-gather.php" sandge5@ecngx308.inmotionhosting.com:%REMOTE_TMP%-step1.php >> "%LOGFILE%" 2>&1
if %errorlevel% neq 0 (
    echo [%time%] ERROR: SCP step1 failed >> "%LOGFILE%"
    goto :done
)

%SSH% "/usr/local/bin/ea-php84 %REMOTE_TMP%-step1.php %OFFICIAL_ID% > %REMOTE_TMP%-prompt.json; rm %REMOTE_TMP%-step1.php" >> "%LOGFILE%" 2>&1
if %errorlevel% neq 0 (
    echo [%time%] ERROR: SSH step1 failed >> "%LOGFILE%"
    goto :done
)

%SCP% sandge5@ecngx308.inmotionhosting.com:%REMOTE_TMP%-prompt.json "%TMPDIR%\stmt-%OFFICIAL_NAME%-prompt.json" >> "%LOGFILE%" 2>&1
if %errorlevel% neq 0 (
    echo [%time%] ERROR: SCP download prompt failed >> "%LOGFILE%"
    goto :done
)

:: Check if disabled
findstr /c:"\"status\":\"disabled\"" "%TMPDIR%\stmt-%OFFICIAL_NAME%-prompt.json" >nul 2>&1
if %errorlevel% equ 0 (
    echo [%time%] Collection is disabled. Exiting. >> "%LOGFILE%"
    goto :done
)

echo [%time%] Step 1 complete. >> "%LOGFILE%"

:: --- Step 2: Extract prompt and call claude -p ---
echo [%time%] Step 2: Extracting prompt... >> "%LOGFILE%"

%PHP% "c:\tpb2\scripts\maintenance\collect-statements-step2-extract.php" "%TMPDIR%\stmt-%OFFICIAL_NAME%-prompt.json" "%TMPDIR%\stmt-%OFFICIAL_NAME%-claude-input.txt" >> "%LOGFILE%" 2>&1
if %errorlevel% neq 0 (
    echo [%time%] ERROR: Prompt extraction failed >> "%LOGFILE%"
    goto :done
)

echo [%time%] Calling claude -p with web search for %OFFICIAL_NAME%... >> "%LOGFILE%"

type "%TMPDIR%\stmt-%OFFICIAL_NAME%-claude-input.txt" | claude -p --allowedTools "WebSearch,WebFetch" > "%TMPDIR%\stmt-%OFFICIAL_NAME%-claude-response.json" 2>> "%LOGFILE%"
if %errorlevel% neq 0 (
    echo [%time%] ERROR: claude -p failed >> "%LOGFILE%"
    goto :done
)

echo [%time%] Step 2 complete. >> "%LOGFILE%"

:: --- Step 3: Upload response and run insert on server ---
echo [%time%] Step 3: Uploading response and inserting %OFFICIAL_NAME% statements... >> "%LOGFILE%"

%SCP% "c:\tpb2\scripts\maintenance\collect-statements-step3-insert.php" sandge5@ecngx308.inmotionhosting.com:%REMOTE_TMP%-step3.php >> "%LOGFILE%" 2>&1
%SCP% "%TMPDIR%\stmt-%OFFICIAL_NAME%-claude-response.json" sandge5@ecngx308.inmotionhosting.com:%REMOTE_TMP%-response.json >> "%LOGFILE%" 2>&1

if %errorlevel% neq 0 (
    echo [%time%] ERROR: SCP step3 failed >> "%LOGFILE%"
    goto :done
)

%SSH% "/usr/local/bin/ea-php84 %REMOTE_TMP%-step3.php %REMOTE_TMP%-response.json %OFFICIAL_ID%; rm %REMOTE_TMP%-step3.php %REMOTE_TMP%-response.json %REMOTE_TMP%-prompt.json" >> "%LOGFILE%" 2>&1

echo [%time%] Step 3 complete. >> "%LOGFILE%"

:done
echo END: %date% %time% >> "%LOGFILE%"
echo ============================================================ >> "%LOGFILE%"
endlocal
