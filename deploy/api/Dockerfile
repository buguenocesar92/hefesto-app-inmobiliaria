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
# =============================================================================
# CVE-2026-109618 / CVE-2026-109619 (PHP2Shell, 2026-10-22)
# =============================================================================
# La imagen se construye con PHP 8.3.36, que es la version PARCHEADA
# de la serie 8.3. Las versiones parcheadas de PHP son
# 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12.
#
# Verificado el 2026-10-22 en el servidor de desarrollo, con el tag exacto:
#
#     $ docker run --rm php:8.3-fpm-bookworm php -v
#     PHP 8.3.36 (cli) (built: Oct 21 2026 16:00:25)
#
# El tag `php:8.3-fpm-bookworm` es FLOTANTE: no se cambia, se deja constancia.
# El tag resuelve hoy a PHP 8.3.36, que es la version parcheada de la serie
# 8.3. Se deja escrito porque un tag flotante puede mudar sin que nadie lo
# note: la comprobacion va en el candado (api/tests/Unit/), que falla si el
# tag vuelve a quedar por debajo de la version parcheada.
#
# OJO: LA IMAGEN DESPLIEGADA HAY QUE RECONSTRUIRLA. El parche de PHP entra en
# el contenedor por la IMAGEN, no por este repositorio: mientras no se
# reconstruya y se redespliegue, el contenedor sigue con el PHP viejo. El
# endurecimiento de mas abajo (el ini y el pool de php-fpm) es la red de seguridad
# para ese mientras, y lo que sobrevive a un redeploy.
FROM php:8.3-fpm-bookworm AS base

# `intl` y `pdo_pgsql` no son opcionales: la primera para las fechas y los
# nombres en espanol, la segunda para PostgreSQL.
RUN apt-get update && apt-get upgrade -y && apt-get install --no-install-recommends -y \
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
COPY api/composer.json api/composer.lock* ./

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
COPY api/ ./

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
COPY deploy/api/docker/php.ini /usr/local/etc/php/conf.d/inmobiliaria.ini

# El entrypoint lo invoca el CMD (`bash docker/entrypoint.sh`) desde el
# directorio de trabajo, asi que tiene que quedar dentro de la imagen.
COPY deploy/api/docker/entrypoint.sh /var/www/html/docker/entrypoint.sh

# Endurecimiento PHP2Shell (CVE-2026-109618 / CVE-2026-109619):
# la via CONFIRMADA (session.upload_progress) apagada, y los
# trabajadores de php-fpm reciclados cada 100 peticiones.
COPY deploy/api/docker/99-php2shell-hardening.ini /usr/local/etc/php/conf.d/99-php2shell-hardening.ini
COPY deploy/api/docker/zz-php2shell-fpm.conf /usr/local/etc/php-fpm.d/zz-php2shell-hardening.conf

EXPOSE 8000

CMD ["bash", "docker/entrypoint.sh"]