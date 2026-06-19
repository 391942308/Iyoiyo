@echo off
cd /d "%~dp0"
title IYOIYO 服务控制台

:: 生成当天日期变量
for /f "tokens=2 delims==" %%I in ('wmic os get localdatetime /value ^| find "="') do set datetime=%%I
set today=%datetime:~0,8%

echo ========================================
echo   IYOIYO 正在启动...
echo ========================================
echo.
echo  PHP 服务器启动中（后台静默）...
start /B "" "iyoiyo\php\php.exe" -S 0.0.0.0:19266 "iyoiyo\index.php" >> "iyoiyo\log\web%today%.log" 2>&1
echo.
echo  cloudflared 隧道启动中，请稍候...
start /B "" cloudflared.exe tunnel --url http://localhost:19266 > "iyoiyo\tunnel.log" 2>&1
echo ========================================
echo.
echo  启动完成！
echo  本地访问：http://localhost:19266
echo  外网地址：启动后约 5 秒可在管理后台查看（或查看 iyoiyo\tunnel.log）
echo.
echo  如需停止服务，请按任意键，然后输入 yes 确认。
echo ========================================
pause >nul

:confirm
set /p input=确定要停止所有服务吗？(输入 yes 确认，其它键取消): 
if /i "%input%"=="yes" goto stop
echo 已取消，服务继续运行。如需再次退出请按任意键。
pause >nul
goto confirm

:stop
echo 正在停止服务...
taskkill /f /im php.exe 2>nul
taskkill /f /im cloudflared.exe 2>nul
echo 服务已停止。
pause