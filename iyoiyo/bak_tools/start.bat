@echo off
cd /d %~dp0
start start_web.bat
timeout /t 2
start start_tunnel.bat
echo 服务已启动，请稍后查看隧道地址...
pause
