@echo off
rem FULLBASE QA - uruchomienie lokalne (PHP z PATH). Adres: http://localhost:8091
cd /d "%~dp0.."
echo FULLBASE QA: http://localhost:8091  (Ctrl+C konczy)
start "" http://localhost:8091
php -S localhost:8091 -t public
