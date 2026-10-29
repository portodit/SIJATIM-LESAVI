@echo off
cd /d "%~dp0"
title SIJATIM - Worker pembuat file invoice (jangan ditutup)
echo Worker pembuat file Excel dan PDF invoice sedang berjalan.
echo Biarkan jendela ini terbuka selama aplikasi dipakai. Tutup dengan Ctrl+C.
echo.
:loop
php -d memory_limit=1024M artisan queue:work database --queue=default --sleep=1 --tries=3 --timeout=300 --memory=1024
echo Worker berhenti, dijalankan ulang dalam 3 detik...
timeout /t 3 >nul
goto loop
