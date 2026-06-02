@echo off
cd /d "%~dp0"
echo Starting Mini ERP dev servers...
start "PHP Server" cmd /k "php artisan serve"
timeout /t 2 /nobreak >nul
start "Vite Dev" cmd /k "npm run dev"
echo Done! Open http://localhost:8000
