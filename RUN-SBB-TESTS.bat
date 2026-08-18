@echo off
setlocal enabledelayedexpansion
cd /d "%~dp0"

REM ===================================================================
REM  SmartPRS - SBB ingest path - TEST RUNNER
REM
REM  SAFE TO RUN AT ANY TIME.
REM  Tests run against an isolated IN-MEMORY SQLite database
REM  (see phpunit.xml: DB_CONNECTION=sqlite, DB_DATABASE=:memory:).
REM  Your live MySQL 'smartprs' database is NEVER touched, read or written.
REM  No migration is applied to your real data by this script.
REM
REM  Output is written to  sbb-test-results.txt  next to this file.
REM ===================================================================

set "OUT=sbb-test-results.txt"

echo. > "%OUT%"
echo ============================================================ >> "%OUT%"
echo  SmartPRS - SBB ingest path - test run                       >> "%OUT%"
echo  Folder : %CD%                                               >> "%OUT%"
echo  Date   : %DATE% %TIME%                                      >> "%OUT%"
echo ============================================================ >> "%OUT%"
echo. >> "%OUT%"

echo.
echo  SmartPRS - SBB test runner
echo  Tests use in-memory SQLite. Your live database is not touched.
echo.

REM ---- locate PHP -------------------------------------------------
set "PHPEXE="
where php >nul 2>&1 && set "PHPEXE=php"

if "%PHPEXE%"=="" (
  for /d %%D in ("C:\laragon\bin\php\php-*") do (
    if exist "%%D\php.exe" set "PHPEXE=%%D\php.exe"
  )
)

if "%PHPEXE%"=="" (
  echo ERROR: PHP not found on PATH and not found under C:\laragon\bin\php\ >> "%OUT%"
  echo ERROR: PHP not found. Open Laragon ^> Terminal and run this file from there.
  echo.
  pause
  exit /b 1
)

echo PHP binary: %PHPEXE% >> "%OUT%"
"%PHPEXE%" -v >> "%OUT%" 2>&1
echo. >> "%OUT%"

REM ---- check vendor -----------------------------------------------
if not exist "vendor\bin\pest" (
  echo ERROR: vendor\bin\pest not found. Run "composer install" first. >> "%OUT%"
  echo ERROR: vendor\bin\pest not found. Run "composer install" in this folder first.
  echo.
  pause
  exit /b 1
)

REM ---- 1. the SBB suite -------------------------------------------
echo. >> "%OUT%"
echo ------------------------------------------------------------ >> "%OUT%"
echo  1 of 2 : SBB ingest tests (tests/Feature/SbbIngestTest.php)  >> "%OUT%"
echo ------------------------------------------------------------ >> "%OUT%"
echo. >> "%OUT%"

echo  [1 of 2] Running SBB ingest tests...
"%PHPEXE%" vendor\bin\pest tests/Feature/SbbIngestTest.php >> "%OUT%" 2>&1
set "SBB_RC=!ERRORLEVEL!"
echo. >> "%OUT%"
echo EXIT CODE (SBB suite): !SBB_RC! >> "%OUT%"

REM ---- 2. the whole suite, to catch regressions -------------------
echo. >> "%OUT%"
echo ------------------------------------------------------------ >> "%OUT%"
echo  2 of 2 : Full test suite (regression check)                  >> "%OUT%"
echo ------------------------------------------------------------ >> "%OUT%"
echo. >> "%OUT%"

echo  [2 of 2] Running the full suite...
"%PHPEXE%" vendor\bin\pest >> "%OUT%" 2>&1
set "ALL_RC=!ERRORLEVEL!"
echo. >> "%OUT%"
echo EXIT CODE (full suite): !ALL_RC! >> "%OUT%"

REM ---- summary ----------------------------------------------------
echo. >> "%OUT%"
echo ============================================================ >> "%OUT%"
if "!SBB_RC!"=="0" (
  echo  SBB SUITE  : PASS >> "%OUT%"
) else (
  echo  SBB SUITE  : FAIL ^(exit !SBB_RC!^) >> "%OUT%"
)
if "!ALL_RC!"=="0" (
  echo  FULL SUITE : PASS >> "%OUT%"
) else (
  echo  FULL SUITE : FAIL ^(exit !ALL_RC!^) >> "%OUT%"
)
echo ============================================================ >> "%OUT%"

echo.
echo ============================================================
if "!SBB_RC!"=="0" (echo   SBB SUITE  : PASS) else (echo   SBB SUITE  : FAIL)
if "!ALL_RC!"=="0" (echo   FULL SUITE : PASS) else (echo   FULL SUITE : FAIL)
echo ============================================================
echo.
echo  Full output saved to: %CD%\%OUT%
echo  Send that file back to Claude, or just say "tests are done".
echo.
pause
endlocal
