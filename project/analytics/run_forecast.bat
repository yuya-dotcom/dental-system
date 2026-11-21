@echo off
rem EssenciaSmile predictive analytics - one forecast run
rem Usage: run_forecast.bat [live|simulated] [triggered-by]   (defaults: live, scheduler)
rem Used by Windows Task Scheduler and by the dashboard's "Run forecast now"
rem (api/forecast_run.php sets ES_PYTHON to the python.exe it found).
cd /d "%~dp0"
set "SRC=%~1"
if "%SRC%"=="" set "SRC=live"
set "WHO=%~2"
if "%WHO%"=="" set "WHO=scheduler"
set "PY=%ES_PYTHON%"
if "%PY%"=="" set "PY=python"
if not exist "..\cron\logs" mkdir "..\cron\logs"
"%PY%" run_forecast.py --source %SRC% --triggered-by "%WHO%" >> "..\cron\logs\forecast.log" 2>&1
