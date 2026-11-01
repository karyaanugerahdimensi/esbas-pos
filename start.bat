@echo off
title KAD Tech Web Service
echo ===================================================
echo   Memulai Web Service KAD Tech...
echo ===================================================
cd /d "%~dp0"

echo Membuka browser ke http://localhost:8000 ...
start http://localhost:8000

echo Menjalankan PHP Built-in Server pada port 8000...
echo Tekan Ctrl + C di jendela ini untuk menghentikan server.
echo.
php -S localhost:8000
pause
