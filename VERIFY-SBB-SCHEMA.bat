@echo off
setlocal enabledelayedexpansion
cd /d "%~dp0"

REM ===================================================================
REM  SmartPRS - SBB schema verification
REM
REM  READ-ONLY. Nothing is created, altered or written.
REM  Answers: did the three SBB migrations actually reach THIS database?
REM
REM  Output goes to  sbb-schema-status.txt  next to this file.
REM ===================================================================

set "OUT=sbb-schema-status.txt"

set "PHPEXE="
where php >nul 2>&1 && set "PHPEXE=php"
if "%PHPEXE%"=="" (
  for /d %%D in ("C:\laragon\bin\php\php-*") do (
    if exist "%%D\php.exe" set "PHPEXE=%%D\php.exe"
  )
)
if "%PHPEXE%"=="" (
  echo ERROR: PHP not found. Run this from a Laragon terminal.
  pause
  exit /b 1
)

echo. > "%OUT%"
echo Date: %DATE% %TIME% >> "%OUT%"
echo Folder: %CD% >> "%OUT%"
echo. >> "%OUT%"

echo.
echo  Checking SBB schema (read-only)...
echo.

echo ============================================================ >> "%OUT%"
echo  migrate:status  (SBB rows only)                             >> "%OUT%"
echo ============================================================ >> "%OUT%"
"%PHPEXE%" artisan migrate:status >> "%OUT%" 2>&1

echo. >> "%OUT%"
"%PHPEXE%" sbb-verify.php >> "%OUT%" 2>&1

type "%OUT%"

echo.
echo  Saved to: %CD%\%OUT%
echo.
pause
endlocal
