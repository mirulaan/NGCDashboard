@echo off
setlocal
cd /d "%~dp0"
echo ==========================================================
echo   Launching NGC Fleet Deployer with Administrator Rights  
echo ==========================================================
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Deploy-AgentFleet.ps1" %*
if %ERRORLEVEL% NEQ 0 (
    echo.
    echo Fleet deployer finished with code %ERRORLEVEL%.
)
pause
