# Script PowerShell untuk menjalankan ESBAS POS Web Service
$Host.UI.RawUI.WindowTitle = "ESBAS POS Web Service"
Write-Host "===================================================" -ForegroundColor Cyan
Write-Host "  Memulai Web Service ESBAS POS..." -ForegroundColor Cyan
Write-Host "===================================================" -ForegroundColor Cyan

Set-Location -Path $PSScriptRoot

Write-Host "Membuka browser ke http://localhost:8000 ..." -ForegroundColor Yellow
Start-Process "http://localhost:8000"

Write-Host "Menjalankan PHP Built-in Server pada port 8000..." -ForegroundColor Green
Write-Host "Tekan Ctrl + C di jendela ini untuk menghentikan server." -ForegroundColor Gray
Write-Host ""

php -S localhost:8000
