@echo off
cd /d "%~dp0"
if not exist "logs" mkdir "logs"
"C:\xampp\php\php.exe" mark_no_shows.php >> "logs\no_shows.log" 2>&1
