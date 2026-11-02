# API Laravel (PHP 8.3 + Apache). La misma imagen sirve para la API y para el programador de tareas.
FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

FROM php:8.3-apache

# Extensiones que usa el sistema y el cliente de MySQL/MariaDB para respaldos (mysqldump / mysql).
RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev libicu-dev libpng-dev libjpeg-dev libfreetype6-dev mariadb-client unzip \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql zip intl gd bcmath exif \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*

# Apache sirve backend/public.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
 && { echo 'upload_max_filesize=20M'; echo 'post_max_size=24M'; echo 'memory_limit=256M'; echo 'expose_php=Off'; } > /usr/local/etc/php/conf.d/deurstore.ini

WORKDIR /var/www/html
COPY --from=vendor /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY backend/ ./
RUN composer dump-autoload --optimize --no-dev \
 && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private storage/app/public bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

COPY docker/api/entrypoint.sh /usr/local/bin/deurstore-entrypoint
RUN chmod +x /usr/local/bin/deurstore-entrypoint

ENTRYPOINT ["deurstore-entrypoint"]
CMD ["apache2-foreground"]
