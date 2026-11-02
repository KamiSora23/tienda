#!/bin/sh
# Arranque de la API: espera a MySQL, aplica migraciones (solo el servicio "api") y deja la configuración en caché.
set -e
cd /var/www/html

# Generar la clave no necesita base de datos ni APP_KEY: se ejecuta directo.
case "$*" in
  *key:generate*) exec "$@" ;;
esac

# Las carpetas de storage viven en un volumen: se crean si es la primera vez.
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private storage/app/public
chown -R www-data:www-data storage bootstrap/cache

if [ -z "$APP_KEY" ]; then
  echo "ERROR: falta APP_KEY en el archivo .env. Genérala con:"
  echo "  docker compose run --rm --no-deps api php artisan key:generate --show"
  exit 1
fi

echo "Esperando a la base de datos ${DB_HOST}:${DB_PORT}..."
i=0
until php -r 'try{new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"),getenv("DB_USERNAME"),getenv("DB_PASSWORD"));exit(0);}catch(Throwable $e){exit(1);}'; do
  i=$((i+1)); [ "$i" -gt 60 ] && echo "La base de datos no respondió." && exit 1
  sleep 2
done

php artisan config:clear >/dev/null
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force
  php artisan deurstore:install --no-interaction >/dev/null 2>&1 || true   # solo verifica permisos si ya hay empresas
fi
php artisan config:cache
php artisan view:cache >/dev/null 2>&1 || true

exec "$@"
