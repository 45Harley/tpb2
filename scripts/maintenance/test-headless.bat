@echo off
echo ---------------------------------------- >> "%~dp0logs\headless-test.txt"
echo START: %date% %time% >> "%~dp0logs\headless-test.txt"
claude -p "Say the current date and time. One sentence only." >> "%~dp0logs\headless-test.txt" 2>&1
echo EXIT: %errorlevel% at %time% >> "%~dp0logs\headless-test.txt"
echo ---------------------------------------- >> "%~dp0logs\headless-test.txt"
