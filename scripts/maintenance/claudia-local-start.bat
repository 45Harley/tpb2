@echo off
setlocal

:: ============================================================
:: Start Claudia Local Pipeline
:: 1. PHP built-in server on port 9876 (listener)
:: 2. Reverse SSH tunnel (server:9876 -> local:9876)
:: ============================================================

echo Starting Claudia local listener on port 9876...
start "Claudia Listener" cmd /c "C:\xampp\php\php.exe -S 0.0.0.0:9876 c:\tpb2\scripts\maintenance\claudia-local-listener.php"

timeout /t 2 >nul

echo Starting reverse SSH tunnel (server:9876 -> local:9876)...
echo Press Ctrl+C to stop the tunnel.
echo (Keepalive enabled: ping every 30s, auto-reconnect on drop)
:tunnel_loop
ssh -R 9876:localhost:9876 sandge5@ecngx308.inmotionhosting.com -p 2222 -N -o ServerAliveInterval=30 -o ServerAliveCountMax=3 -o ExitOnForwardFailure=yes
echo Tunnel dropped. Reconnecting in 5 seconds...
timeout /t 5 >nul
goto tunnel_loop

endlocal
