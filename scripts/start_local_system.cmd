@echo off
setlocal
cd /d "%~dp0.."
echo Normal local system: http://127.0.0.1:8000/login.html
echo Uses your normal configured database. Leave this window open while testing.
"C:\xampp\php\php.exe" -S 127.0.0.1:8000 -t php
if errorlevel 1 pause
endlocal
