@echo off
setlocal
title Iniciar Deurstore ERP
rem Rutas relativas a este archivo: el proyecto funciona en cualquier carpeta.
set "ROOT=%~dp0"

rem Busca el PHP 8.3 que tenga instalado Laragon (la version exacta cambia con cada actualizacion).
set "PHP="
for /d %%D in ("C:\laragon\bin\php\php-8.3*") do set "PHP=%%D\php.exe"
if not defined PHP (
  where php >nul 2>nul && set "PHP=php"
)
if not defined PHP (
  echo No se encontro PHP 8.3. Instale o inicie Laragon y vuelva a intentarlo.
  pause
  exit /b 1
)

rem Si otro programa ya usa el puerto 8001, la interfaz llamaria a la API equivocada.
netstat -ano | findstr /r /c:"127.0.0.1:8001 .*LISTENING" /c:"0.0.0.0:8001 .*LISTENING" >nul
if not errorlevel 1 (
  echo El puerto 8001 ya esta en uso por otro programa.
  echo Cierrelo o cambie NEXT_PUBLIC_API_URL en app\.env.local y el puerto de la API.
  pause
  exit /b 1
)

echo Iniciando Deurstore ERP...
echo.
start "Deurstore API - no cerrar" cmd /k "cd /d "%ROOT%backend" && "%PHP%" artisan serve --host=127.0.0.1 --port=8001"
start "Deurstore Tareas - no cerrar" cmd /k "cd /d "%ROOT%backend" && "%PHP%" artisan schedule:work"
timeout /t 3 /nobreak > nul
start "Deurstore Web - no cerrar" cmd /k "cd /d "%ROOT%app" && npm run dev"
timeout /t 4 /nobreak > nul
start http://localhost:3000

echo Deurstore se abrio en el navegador.
echo Mantenga abiertas las tres ventanas de servicio.
pause
