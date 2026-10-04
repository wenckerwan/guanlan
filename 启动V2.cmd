@echo off
chcp 65001 >nul
cd /d "%~dp0"
echo 马原知识宇宙 V2 开发模式
echo 启动后访问 http://127.0.0.1:4179
node scripts/dev.mjs
pause
