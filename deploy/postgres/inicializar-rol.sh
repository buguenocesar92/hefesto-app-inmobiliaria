#!/bin/bash
# ---------------------------------------------------------------------------
# Inmobiliaria: crea el rol con el que conecta la aplicacion
# ---------------------------------------------------------------------------
# ADVERTENCIA HONESTA: este script NO se ejecuto. En el equipo donde se
# escribio no habia PostgreSQL ni Docker. Ver docs/03 y docs/10.
#
# POR QUE HAY DOS ROLES Y NO UNO
# ------------------------------
# La imagen oficial de PostgreSQL ejecuta `initdb --username="$POSTGRES_USER"`,
# y el mensaje de error de la propia imagen habla del "superuser password".
# Es decir: el rol de `POSTGRES_USER` NACE superusuario.
#
# La documentacion de PostgreSQL 18 lo dice de forma textual:
#
#   "Superusers and roles with the BYPASSRLS attribute always bypass the row
#    security system when accessing a table. Table owners normally bypass row
#    security as well, though a table owner can choose to be subject to row
#    security with ALTER TABLE ... FORCE ROW LEVEL SECURITY."
#
# La segunda frase es la clave: `FORCE ROW LEVEL SECURITY` sujeta al DUENO de la
# tabla, pero NO al superusuario. Si la aplicacion conectara con el rol de
# `POSTGRES_USER`, ninguna politica se aplicaria y el aislamiento entre
# empresas quedaria decorativo. Por eso este script crea un rol aparte, que es
# el unico que usa la aplicacion.
#
# QUE HACE, EN ORDEN
# ------------------
# 1. Falla ruidosamente si falta alguna variable obligatoria. Es preferible un
#    despliegue detenido a una base con un rol de contrasena vacia.
# 2. Crea el rol de la aplicacion con TODOS los atributos explicitos,
#    incluidos los que ya son el valor por omision. Un rol verificado no depende
#    de un valor por omision que puede cambiar con una version nueva.
# 3. Le da la propiedad de la base, para que las migraciones de Laravel puedan
#    crear el esquema.
#
# POR QUE ES DUENO DE LA BASE Y NO UN ROL MAS
# --------------------------------------------
# Las migraciones de Laravel se corren con el rol de la aplicacion y necesitan
# crear tablas. La unica forma de dárselo sin permisos de superusuario es que ese
# rol sea el dueno de la base. Desde PostgreSQL 15 el esquema `public` pertenece
# al dueno de la base, asi que con esto el rol puede crear tablas ahi.
#
# Y aqui esta el punto exacto del diseno: al ser el dueno, las tablas quedan a su
# nombre, y por eso el `FORCE ROW LEVEL SECURITY` que ya usan las tablas lo
# sujeta a el. El dueno SI pasa por las politicas. Un superusuario, no.
# ---------------------------------------------------------------------------

set -euo pipefail

fallar() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

# --- Variables obligatorias -----------------------------------------------

[ -n "${POSTGRES_DB:-}" ] || fallar "falta POSTGRES_DB: no se sabe que base arreglar"
[ -n "${APP_DB_ROLE:-}" ] || fallar "falta APP_DB_ROLE: no se sabe que rol crear"
[ -n "${APP_DB_PASSWORD:-}" ] || fallar "falta APP_DB_PASSWORD: no se crea un rol sin contrasena"

# --- Creacion del rol -----------------------------------------------------

psql --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" --no-psqlrc \
     --set=ON_ERROR_STOP=1 \
     --set=rol="$APP_DB_ROLE" \
     --set=base="$POSTGRES_DB" \
     --set=clave="$APP_DB_PASSWORD" <<'SQL'
CREATE ROLE :"rol" WITH LOGIN
    NOSUPERUSER
    NOCREATEDB
    NOCREATEROLE
    NOINHERIT
    NOBYPASSRLS
    PASSWORD :'clave';

-- El rol queda como dueno de la base para que las migraciones de Laravel
-- puedan crear el esquema. Ver la cabecera: por eso lo sujeta el
-- FORCE ROW LEVEL SECURITY y no lo salta.
ALTER DATABASE :"base" OWNER TO :"rol";
SQL

printf '[rol] el rol %s quedo creado y es dueno de la base %s\n' "$APP_DB_ROLE" "$POSTGRES_DB"