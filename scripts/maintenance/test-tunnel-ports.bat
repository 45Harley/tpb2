@echo off
:: ============================================================
:: Check which ports have ghost bindings on server
:: Run this BEFORE claudia-local-start.bat to find a clean port
:: ============================================================

echo === Checking server ports 9876-9999 ===
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "netstat -tlnp 2>/dev/null | grep -E '98[0-9][0-9]|9999' || echo ALL CLEAR - no ghost ports"
echo.
pause
