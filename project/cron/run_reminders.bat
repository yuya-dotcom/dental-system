@echo off
cd /d "%~dp0"
if not exist "logs" mkdir "logs"
"C:\xampp\php\php.exe" send_appointment_reminders.php >> "logs\sms_reminders.log" 2>&1
