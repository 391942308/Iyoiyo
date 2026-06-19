@echo off
set PHP_PATH=%~dp0php\php.exe
set DOC_ROOT=%~dp0
cd /d "%DOC_ROOT%"
echo Starting IYOIYO server on port 19266...
"%PHP_PATH%" -S 0.0.0.0:19266 index.php
echo Server running at http://localhost:19266
pause