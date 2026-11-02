# Deurstore ERP

Sistema comercial multiempresa: punto de venta, inventario con lotes FEFO, compras, caja, clientes,
promociones, gastos, reportes y un portal web con carrito, recojo en tienda y delivery con mapa.

| Carpeta | Contenido |
| --- | --- |
| `backend/` | API Laravel 11 (PHP 8.3, MySQL 8) |
| `app/` | Interfaz web y portal público (React 19 con vinext) |
| `docker/`, `docker-compose.yml` | Despliegue con Docker |

## Instalar en un servidor

Ver **[DEPLOY.md](DEPLOY.md)**: Docker Compose, archivo `.env`, primera instalación y HTTPS.

## Desarrollo local (Windows con Laragon)

1. Copia `backend/.env.example` a `backend/.env`, ajusta la base de datos y ejecuta en `backend/`:
   `composer install`, `php artisan key:generate` y `php artisan migrate --seed` (datos de demostración).
2. En `app/`: `npm install` y copia `.env.example` a `.env.local`.
3. Ejecuta `iniciar-deurstore.bat`: API en `http://127.0.0.1:8001` e interfaz en `http://localhost:3000`.

Pruebas: `php artisan test` en `backend/` y `npm test` en `app/`.
