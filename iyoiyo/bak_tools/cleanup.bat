@echo off
title IYOIYO 残留进程清理工具
cd /d %~dp0

set "PHP_NAME=php.exe"
set "TUNNEL_NAME=cloudflared.exe"
set "WEB_PORT=19266"

echo =======================================
echo IYOIYO 残留进程清理工具
echo =======================================
echo.

set "KILLED=0"

:: 1. 根据进程名结束 PHP
echo [1] 检查进程 %PHP_NAME% ...
tasklist /fi "imagename eq %PHP_NAME%" 2>nul | find /i "%PHP_NAME%" >nul
if errorlevel 1 (
    echo     未找到 %PHP_NAME% 进程。
) else (
    echo     发现 %PHP_NAME% 进程，正在终止...
    taskkill /f /im %PHP_NAME% >nul 2>&1
    if errorlevel 1 (
        echo     终止失败，可能需要管理员权限。
    ) else (
        echo     已终止 %PHP_NAME% 进程。
        set /a KILLED+=1
    )
)

echo.
:: 2. 根据进程名结束 cloudflared
echo [2] 检查进程 %TUNNEL_NAME% ...
tasklist /fi "imagename eq %TUNNEL_NAME%" 2>nul | find /i "%TUNNEL_NAME%" >nul
if errorlevel 1 (
    echo     未找到 %TUNNEL_NAME% 进程。
) else (
    echo     发现 %TUNNEL_NAME% 进程，正在终止...
    taskkill /f /im %TUNNEL_NAME% >nul 2>&1
    if errorlevel 1 (
        echo     终止失败，可能需要管理员权限。
    ) else (
        echo     已终止 %TUNNEL_NAME% 进程。
        set /a KILLED+=1
    )
)

:: 3. 根据端口占用结束进程（增强清理）
echo.
echo [3] 检查端口 %WEB_PORT% 占用情况...
setlocal enabledelayedexpansion
for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":%WEB_PORT%" ^| findstr "LISTENING"') do (
    set "PID=%%a"
    if defined PID (
        echo     发现进程 PID: !PID! 占用端口 %WEB_PORT%，正在终止...
        taskkill /f /pid !PID! >nul 2>&1
        if errorlevel 1 (
            echo     终止失败，可能需要管理员权限。
        ) else (
            echo     已终止进程 PID: !PID!
            set /a KILLED+=1
        )
    )
)
endlocal

echo.
if %KILLED% equ 0 (
    echo 未找到任何残留进程。
) else (
    echo 清理完成，共终止 %KILLED% 个进程/实例。
)

echo.
echo 按任意键退出...
pause >nul