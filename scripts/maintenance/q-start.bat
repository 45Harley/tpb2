@echo off
:: ============================================================
:: Q — AI Queue Poller
:: Runs continuously, polling staging server for AI jobs.
:: Auto-starts on boot via Task Scheduler.
:: ============================================================

echo Starting Q (AI Poller - Remote)...
echo %date% %time%

cd /d c:\tpb2
C:\xampp\php\php.exe scripts\maintenance\ai-poller.php remote

echo Q stopped: %date% %time%
pause
