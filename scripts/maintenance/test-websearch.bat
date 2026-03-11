@echo off
echo ---------------------------------------- >> "%~dp0logs\websearch-test.txt"
echo START: %date% %time% >> "%~dp0logs\websearch-test.txt"
claude -p "Search the web for today's top news headline from AP News. Return just the headline and URL in JSON format: {\"headline\": \"...\", \"url\": \"...\"}" --allowedTools "WebSearch,WebFetch" >> "%~dp0logs\websearch-test.txt" 2>&1
echo EXIT: %errorlevel% at %time% >> "%~dp0logs\websearch-test.txt"
echo ---------------------------------------- >> "%~dp0logs\websearch-test.txt"
