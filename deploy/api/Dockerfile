# =============================================================================
# Hefesto Studio — Hefesto Inmobiliaria: imagen de la API
# =============================================================================
# VERSIONES FIJADAS
# -----------------
# PHP, extensions y Composer van con version exacta, no con `latest`. Una
# imagen que cambia sola entre dos despliegues hace que un fallo no se pueda
# reproducir, y en una aplicacion con migraciones eso es la peor combinacion
# posible.
#
# La digest de la imagen base la fija el despliegue, no este archivo, porque
# cambia con cada actualizacion de seguridad. Ver docs/04.
# =============================================================================

# -----------------------------------------------------------------------------
# Dependencias del sistema
# -----------------------------------------------------------------------------
FROM php:8.3-fpm-bookworm AS base

# `intl` y `pdo_pgsql` no son opcionales: la primera para las fechas y los
# nombres en espanol, la segunda para PostgreSQL.
RUN apt-get update && apt-get install --no-install-recommends -y \
        git \
        unzip \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        supervisor \
        nginx \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        intl \
        pdo_pgsql \
        opcache \
        zip

COPY --from=composer:2.8.8 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# -----------------------------------------------------------------------------
# Dependencias de PHP
# -----------------------------------------------------------------------------
# En una capa aparte, para que `composer install` no se re-ejecute en cada cambio
# de codigo. Esta capa solo se invalida cuando cambia `composer.json` o
# `composer.lock`.
COPY composer.json composer.lock* ./

# Sin `--no-scripts`: el descubrimiento de paquetes de Laravel corre aqui y hace
# falta para que el cache de configuracion exista despues.
RUN composer install \
        --no-dev \
        --no-progress \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

# -----------------------------------------------------------------------------
# Codigo de la aplicacion
# -----------------------------------------------------------------------------
COPY . .

# -----------------------------------------------------------------------------
# Permisos
# -----------------------------------------------------------------------------
# `storage` y `bootstrap/cache` tienen que ser escribibles por el usuario de
# PHP. Un volumen montado encima conserva los permisos del host, asi que
# tambien se corrigen en el entrypoint del despliegue.
RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs storage/fotos bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# -----------------------------------------------------------------------------
# Configuracion de PHP
# -----------------------------------------------------------------------------
COPY docker/php.ini /usr/local/etc/php/conf.d/inmobiliaria.ini

EXPOSE 8000

CMD ["bash", "docker/entrypoint.sh"]