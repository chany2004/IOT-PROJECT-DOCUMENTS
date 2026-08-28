@echo off
setlocal
set "PROJECT_DIR=%~dp0"
set "PYTHON_EXE=%USERPROFILE%\.platformio\penv\Scripts\python.exe"
set "SERVER_ID=fallguard-esp32-tinkered-workspace-1"

if not exist "%PYTHON_EXE%" (
  echo PlatformIO Python was not found at:
  echo %PYTHON_EXE%
  pause
  exit /b 1
)

powershell.exe -NoProfile -Command "try { $state = Invoke-RestMethod -UseBasicParsing -TimeoutSec 1 'http://127.0.0.1:8765/api/live'; if ($state.server_id -eq '%SERVER_ID%') { exit 0 }; exit 2 } catch { exit 1 }"
set "BRIDGE_STATUS=%ERRORLEVEL%"

if "%BRIDGE_STATUS%"=="2" (
  echo Port 8765 is already used by a different FallGuard dashboard.
  echo Close the old dashboard bridge, then run this launcher again.
  pause
  exit /b 2
)

if not "%BRIDGE_STATUS%"=="0" (
  start "FallGuard Bridge" "%PYTHON_EXE%" "%PROJECT_DIR%dashboard\server.py"
  timeout /t 1 /nobreak >nul
  powershell.exe -NoProfile -Command "try { $state = Invoke-RestMethod -UseBasicParsing -TimeoutSec 2 'http://127.0.0.1:8765/api/live'; if ($state.server_id -eq '%SERVER_ID%') { exit 0 } } catch {}; exit 1"
  if errorlevel 1 (
    echo The current FallGuard bridge could not start on port 8765.
    pause
    exit /b 1
  )
)
start "" "http://127.0.0.1:8765/"
endlocal
