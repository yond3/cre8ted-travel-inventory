@echo off
setlocal
rem Set before PowerShell startup, without changing installed or user configuration.
set "POWERSHELL_TELEMETRY_OPTOUT=1"
cd /d "%~dp0.."
echo Starting an ISOLATED Phase 4 test preview. Your normal database is not used.
python tests/phase1_security.py --phase4 --preview --temp-parent "%TEMP%"
if errorlevel 1 (
  echo Preview did not start successfully. Keep this output for review.
  pause
)
endlocal
