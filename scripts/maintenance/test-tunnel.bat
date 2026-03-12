@echo off
:: ============================================================
:: Test Claudia Tunnel — run AFTER claudia-local-start.bat
:: Tests: local listener, server port, end-to-end forwarding
:: ============================================================

echo === Test 1: Local listener on port 9876 ===
curl -s --max-time 5 http://localhost:9876/ 2>nul
echo.
echo.

echo === Test 2: Server port 9876 bound? ===
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "netstat -tlnp 2>/dev/null | grep 9876 || echo PORT 9876 NOT LISTENING"
echo.

echo === Test 3: End-to-end (server -> tunnel -> local) ===
ssh sandge5@ecngx308.inmotionhosting.com -p 2222 "curl -s --max-time 10 http://127.0.0.1:9876/ 2>&1; echo; echo EXIT:$?"
echo.

echo === Done ===
pause
