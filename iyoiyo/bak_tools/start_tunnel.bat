@echo off
cd /d %~dp0
cloudflared.exe tunnel --url http://localhost:19266 --no-autoupdate > tunnel.log 2>&1
pause