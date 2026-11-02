# Instalar Deurstore en un servidor con Docker

Todo corre en contenedores: base de datos MySQL, API Laravel, tareas programadas, interfaz web y un
proxy que lo une en una sola dirección (`/api` → API, el resto → interfaz).

## 1. Requisitos del servidor

- Linux (Ubuntu 22.04/24.04 recomendado) con al menos **2 GB de RAM** y 10 GB de disco.
- **Docker** y **Docker Compose**:

  ```bash
  curl -fsSL https://get.docker.com | sh
  sudo usermod -aG docker $USER   # cierra sesión y vuelve a entrar
  docker compose version
  ```

## 2. Descargar el código

```bash
git clone https://github.com/TU-USUARIO/deurstore.git
cd deurstore
```

## 3. Crear el archivo `.env`

```bash
cp .env.docker.example .env
nano .env
```

Completa las líneas marcadas con `<-- CAMBIAR`:

| Variable | Qué poner |
| --- | --- |
| `APP_PORT` | Puerto donde abrirá el sistema (por defecto `8080`). |
| `APP_URL` y `FRONTEND_URL` | La misma dirección pública, sin `/` al final: `https://erp.tuempresa.com` o `http://IP-DEL-SERVIDOR:8080`. |
| `APP_KEY` | Clave de cifrado. Genérala con el comando de abajo y pégala. **No la cambies después**. |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | Dos contraseñas largas y distintas (sin el carácter `$`). |
| `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | Correo que envía los códigos y comprobantes. En Gmail, `MAIL_PASSWORD` es una **contraseña de aplicación** de 16 letras sin espacios ([myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords)). |

Generar `APP_KEY`:

```bash
docker compose run --rm --no-deps api php artisan key:generate --show
```

Copia lo que muestra (empieza con `base64:`) en `APP_KEY=` del `.env`.

## 4. Primera instalación

Opción A, automática: completa en `.env` las variables `INSTALL_*` (empresa, RUC, administrador y
superadministrador con sus contraseñas). Al arrancar por primera vez se crean solas. **Después borra
esas contraseñas del `.env`.**

Opción B, a mano (pregunta los datos):

```bash
docker compose up -d --build
docker compose exec api php artisan deurstore:install
```

No uses `migrate --seed` en un servidor real: crea una empresa de demostración con contraseñas públicas.

## 5. Arrancar

```bash
docker compose up -d --build
docker compose ps          # todos deben estar "running" / "healthy"
```

La primera construcción tarda varios minutos. Luego abre `APP_URL` en el navegador:

- El **administrador de la empresa** entra con su correo y contraseña.
- El **superadministrador** entra con su correo, contraseña y el código que le llega por correo; llega
  al *Panel de plataforma*.
- El portal público queda en `APP_URL/portal`.

## 6. HTTPS con dominio (recomendado)

El contenedor `proxy` escucha en `APP_PORT` por HTTP. Para HTTPS pon delante un proxy con
certificados, por ejemplo **Caddy** en el mismo servidor:

```bash
sudo apt install -y caddy
echo 'erp.tuempresa.com {
    reverse_proxy 127.0.0.1:8080
}' | sudo tee /etc/caddy/Caddyfile
sudo systemctl restart caddy
```

Apunta el dominio (registro A) a la IP del servidor y pon `APP_URL=https://erp.tuempresa.com`
(y `FRONTEND_URL` igual) en `.env`; luego `docker compose up -d`. También sirven Cloudflare Tunnel o
Nginx Proxy Manager.

## 7. Actualizar a una nueva versión

```bash
git pull
docker compose up -d --build
```

Las migraciones se aplican solas al arrancar la API. Los datos están en los volúmenes `db_data`
(base de datos) y `storage` (imágenes, respaldos), que no se borran al actualizar.

## 8. Comandos útiles

```bash
docker compose logs -f api                 # errores de la API
docker compose exec api php artisan mail:test tu-correo@gmail.com
docker compose exec api php artisan user:promote-platform correo@ejemplo.com
docker compose exec api php artisan user:login-code correo@ejemplo.com   # si el código no llega
docker compose exec db mysqldump -u root -p deurstore > respaldo.sql      # respaldo manual
docker compose down                        # detener (los datos se conservan)
```

> **Cuidado:** `docker compose down -v` borra los volúmenes, es decir, **toda la base de datos**.

## Si no carga: «The origin has unexpectedly closed the connection»

Ese mensaje lo da Cloudflare (o el túnel/proxy que tengas delante) cuando el servidor corta la
conexión. Revisa en este orden:

1. **¿Están arriba los contenedores?**

   ```bash
   docker compose ps
   ```

   Todos deben decir `running`; `api`, `web` y `proxy` además `(healthy)`. La primera vez la API tarda
   1–3 minutos (migraciones). Si alguno dice `restarting` o `exited`, mira su error:

   ```bash
   docker compose logs --tail=80 api
   docker compose logs --tail=80 web
   docker compose logs --tail=80 proxy
   ```

2. **¿Responde en el propio servidor?** (cambia 8080 si usas otro `APP_PORT`)

   ```bash
   curl -I http://127.0.0.1:8080/proxy-health   # 200 = el proxy funciona
   curl -I http://127.0.0.1:8080/up             # 200 = la API funciona
   curl -I http://127.0.0.1:8080/               # 200 = la web funciona
   ```

3. **Cloudflare / túnel:** el destino debe ser `http://localhost:8080` (o `http://IP:8080`), **no
   `https://`**: el contenedor `proxy` habla HTTP y el HTTPS lo pone Cloudflare. Si usas registros DNS
   con la nube naranja (sin túnel), Cloudflare solo conecta a los puertos 80/443/8080/8443…: usa
   `APP_PORT=80` o `8080`, y en SSL/TLS elige el modo **Flexible** (o pon Caddy con certificado y modo
   *Full*).
4. **Firewall del servidor:** abre el puerto (`sudo ufw allow 8080/tcp`) si entras directo por IP.

## Desarrollo local en Windows (sin Docker)

Sigue funcionando como siempre con Laragon e `iniciar-deurstore.bat` (API en el puerto 8001 e
interfaz en el 3000). Ver `backend/.env.example` y `app/.env.example`.
