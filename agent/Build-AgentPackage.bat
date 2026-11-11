@echo off
setlocal
echo Launching NGC Telemetry Agent Packager with ExecutionPolicy Bypass...
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Build-AgentPackage.ps1" %*
exit /b %ERRORLEVEL%
