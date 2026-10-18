#!/usr/bin/env bash
# =============================================================================
# Entrypoint de la API de Hefesto Inmobiliaria
# =============================================================================
# QUE HACE, EN ORDEN
#   1. Espera a que PostgreSQL acepte conexiones.
#   2. Corre las migraciones.
#   3. Verifica que el aislamiento entre inmobiliarias este puesto, y FRENA el
#      arranque si no lo esta.
#   4. Siembra los datos de ejemplo SOLO si la base esta vacia.
#   5. Limpia caches y arranca el servidor.
#
# EL PUNTO 3 ES EL IMPORTANTE
# ---------------------------
# Arrancar sin Row Level Security parecieria funcionar: la aplicacion leeria y
# escribiria todo. El problema no apareceria hasta que dos inmobiliarias
# compartieran instalacion. Por eso el arranque ABORTA si el aislamiento no esta,
# en vez de avisar y seguir.
# =============================================================================

set -euo pipefail

cd /var/www/html

# -----------------------------------------------------------------------------
# 1. Esperar a PostgreSQL
# -----------------------------------------------------------------------------
esperar_postgres() {
    local intentos=30
    local espera=2

    echo "Esperando a PostgreSQL en ${DB_HOST:-postgres}:${DB_PORT:-5432}..."

    while [ "${intentos}" -gt 0 ]; do
        if php -r '
            $host = getenv("DB_HOST") ?: "postgres";
            $puerto = getenv("DB_PORT") ?: "5432";
            $socket = @fsockopen($host, (int) $puerto, $errno, $errstr, 3);
            exit($socket ? 0 : 1);
        ' 2>/dev/null; then
            echo "PostgreSQL responde."
            return 0
        fi

        intentos=$((intentos - 1))
        echo "  todavia no responde. Quedan ${intentos} intento(s)."
        sleep "${espera}"
    done

    echo "ERROR: PostgreSQL no respondio a tiempo." >&2
    return 1
}

# -----------------------------------------------------------------------------
# 2 y 3. Migrar y verificar el aislamiento
# -----------------------------------------------------------------------------
migrar_y_verificar() {
    echo "Corriendo migraciones..."
    php artisan migrate --force --no-interaction

    echo "Verificando el aislamiento entre inmobiliarias..."
    # `verificar-aislamiento` sale con codigo distinto de cero si falta una
    # politica, si falta FORCE, o si el rol de la base puede saltarse la RLS.
    # Con `set -e`, un fallo aqui detiene el arranque.
    php artisan inmobiliaria:verificar-aislamiento
}

# -----------------------------------------------------------------------------
# 4. Sembrar si la base esta vacia
# -----------------------------------------------------------------------------
sembrar_si_esta_vacia() {
    # La siembra NO es idempotente para el historial: correrla dos veces crea
    # tramos duplicados. Por eso se decide por el estado, no siempre.
    local conexiones

    conexiones=$(php artisan tinker --execute="
        echo \Illuminate\Support\Facades\DB::table('inmobiliarias')->count();
    " 2>/dev/null || echo "0")

    if [ "${conexiones}" = "0" ]; then
        echo "Base vacia: sembrando datos de ejemplo..."
        php artisan db:seed --force --no-interaction
    else
        echo "La base ya tiene ${conexiones} inmobiliaria(s). No se siembra."
    fi
}

# -----------------------------------------------------------------------------
# 5. Limpiar y arrancar
# -----------------------------------------------------------------------------
limpiar_y_arrancar() {
    echo "Limpiando caches..."
    php artisan config:clear
    php artisan route:clear
    php artisan view:clear

    # El opcache tiene que validar sellos de tiempo en desarrollo: si no, se
    # ven cambios de codigo que ya no existen.
    if [ "${APP_ENV:-production}" = "local" ]; then
        php artisan config:cache
        php artisan route:cache
    fi

    echo "Arrancando la API..."
    exec php artisan serve --host=0.0.0.0 --port="${APP_PORT:-8000}"
}

# -----------------------------------------------------------------------------
# Programa
# -----------------------------------------------------------------------------
esperar_postgres
migrar_y_verificar
sembrar_si_esta_vacia
limpiar_y_arrancar