# 04 - Instalación

> **Estado: este procedimiento NO se ejecutó.** En el entorno donde se escribió
> el proyecto no hay PHP, ni Composer, ni Docker. Ver
> [10](10-PRUEBAS-Y-ESTADO.md).
>
> Los pasos están escritos siguiendo la documentación oficial de Laravel, Nuxt,
> PostgreSQL, Redis y Authentik, y son coherentes con lo que hace el código de
> esta aplicación. Lo que no se puede afirmar sin ejecutarlo es que funcionen tal
> cual: **ese es el primer trabajo de quien lo levante**.

---

## 1. Requisitos

| Componente | Versión | Motivo de esa versión |
|---|---|---|
| PHP | 8.3 | Requisito de Laravel 13 |
| Composer | 2.8 | Fijada en el Dockerfile |
| Node | 22.20 | Fijada en el Dockerfile |
| PostgreSQL | 18.6 | **Obligatorio**, ver abajo |
| Redis | 8.8.3 | Cache, sesiones y colas |

### PostgreSQL no es opcional

El aislamiento entre inmobiliarias está implementado con **Row Level Security**,
que es una función de PostgreSQL. En SQLite o MySQL las políticas no existen, el
aislamiento desaparece **sin error**, y las pruebas de aislamiento pasarían sin
comprobar nada.

---

## 2. Con Docker Compose

La via normal.

### 2.1 Preparar los archivos de entorno

```bash
cd apps/inmobiliaria

# Composición
cp .env.example .env

# API
cp api/.env.example api/.env

# Interfaz
cp web/.env.example web/.env
```

Edite `api/.env` y complete como minimo:

- `DB_PASSWORD`: una clave real, distinta de la de ejemplo.
- `APP_KEY`: se genera en el paso siguiente.
- `AUTHENTIK_CLIENT_ID` y `AUTHENTIK_CLIENT_SECRET`: los de su cliente OIDC.
- `AUTHENTIK_REDIRECT_URI`: debe coincidir **exactamente** con la URL de retorno
  de Authentik.
- `INMOBILIARIA_PREFIJO_EQUIPO`: el prefijo de los equipos. Ver
  [05](05-CONFIGURACION.md).

### 2.2 Levantar

```bash
docker compose -f deploy/docker-compose.dev.yml up --build
```

El `entrypoint` de la API hace, en orden:

1. Espera a PostgreSQL (30 intentos, 2 segundos entre cada uno).
2. Corre las migraciones.
3. **Verifica el aislamiento y frena el arranque si no esta puesto.**
4. Siembra datos de ejemplo solo si la base esta vacia.
5. Limpia caches y arranca.

Que el paso 3 frene el arranque es intencional: arrancar sin Row Level Security
pareceria funcionar, y el problema no apareceria hasta que dos inmobiliarias
compartieran instalación.

### 2.3 Generar la clave

Con el contenedor ya levantado:

```bash
docker compose -f deploy/docker-compose.dev.yml exec api php artisan key:generate
```

---

## 3. Sin Docker

### 3.1 API

```bash
cd apps/inmobiliaria/api

composer install
cp .env.example .env
php artisan key:generate
```

### 3.2 Base de datos

Cree la base y el usuario. **El usuario no debe ser superusuario ni tener
`BYPASSRLS`**:

```sql
CREATE DATABASE inmobiliaria;
CREATE USER inmobiliaria WITH PASSWORD 'una-clave-real';
GRANT ALL PRIVILEGES ON DATABASE inmobiliaria TO inmobiliaria;
```

Para que las pruebas corran, ademas la base de pruebas:

```sql
CREATE DATABASE inmobiliaria_test;
GRANT ALL PRIVILEGES ON DATABASE inmobiliaria_test TO inmobiliaria;
```

No hace falta crear las tablas: las hace `migrate`, y las políticas las crea la
última migración.

### 3.3 Migrar y sembrar

```bash
php artisan migrate
php artisan db:seed
```

### 3.4 Verificar el aislamiento

**Antes de trusting en la instalación:**

```bash
php artisan inmobiliaria:verificar-aislamiento
```

Debe salir con código cero. Si no, la salida dice exactamente que fallo.

### 3.5 Interfaz

```bash
cd apps/inmobiliaria/web

npm ci
npm run dev
```

`npm ci` y no `npm install`: `ci` instala exactamente lo que dice el lockfile y
falla si el manifiesto y el lock no coinciden.

---

## 4. Las tablas

Hay trece. Once se crean solas por migración y quedan con RLS. Dos no:

| | Por que |
|---|---|
| `sessions` | La maneja Laravel; depende del driver de sesión |
| `jobs`, `failed_jobs` | Dependen del driver de cola |

Si usa `SESSION_DRIVER=database` (el valor por omision), cree `sessions` antes de
la primera peticion:

```bash
php artisan session:table
php artisan migrate
```

Con `SESSION_DRIVER=redis` no hace falta.

---

## 5. Estructura del esqueleto

El repositorio trae el nucleo de Laravel, pero no todo lo que genera
`composer create-project laravel/laravel`. estan escritos:

| | |
|---|---|
| `bootstrap/app.php` | Rutas, middleware y manejo de excepciones |
| `routes/web.php` | Toda la API, bajo `/api/v1` |
| `routes/console.php` | El planificador |
| `config/` | `app`-independientes: `auth`, `authentif`, `database`, `inmobiliaria`, `logging`, `session` |
| `artisan`, `public/index.php` | Puntos de entrada |
| `app/`, `database/`, `tests/` | Código de la aplicación |

Los archivos de configuración restantes (`cache`, `filesystems`, `mail`, `queue`,
`view`, `cors`) usan los valores por omision de Laravel 13. Si su instalación
necesita ajustarlos, copie el archivo desde el paquete de Laravel y ajuste lo
minimo.

---

## 6. Comprobar que quedo bien

En este orden. Los tres primeros tienen que pasar antes de cargar datos de
verdad.

### 6.1 El aislamiento esta puesto

```bash
php artisan inmobiliaria:verificar-aislamiento
```

### 6.2 Las pruebas

```bash
# La base de pruebas tiene que existir y ser PostgreSQL.
php artisan test --testsuite=Aislamiento
php artisan test --testsuite=Permisos
php artisan test --testsuite=Operaciones
php artisan test --testsuite=Estados
```

Si corre contra SQLite, **todas pasan sin comprobar nada**. Verifique que
`DB_CONNECTION=pgsql` en `phpunit.xml`.

### 6.3 Las reglas automaticas, a mano

```bash
# Simula la liberacion sin escribir nada:
php artisan inmobiliaria:liberar-reservas --simular

# Y despues, de verdad:
php artisan inmobiliaria:liberar-reservas
```

### 6.4 La interfaz

Abra `http://localhost:3000`. Con la semilla cargada deberia ver:

- El tablero con cuatro estados y sus números.
- Una propiedad con **210 dias en reservada**, arriba en la lista de en curso,
  marcada como colgada.
- Al entrar como `beto@sur.test`, **solo** las propiedades de Beto.

Cuentas de la semilla (solo funcionan si existen en Authentik):

| Correo | Rol | Ve |
|---|---|---|
| `admin@sur.test` | administrador | Toda la inmobiliaria |
| `ana@sur.test` | corredor | Solo su cartera |
| `beto@sur.test` | corredor | Solo su cartera |

---

## 7. Problemas frecuentes

### "Falta el contexto de inmobiliaria (app.tenant_id)"

Una consulta se ejecuto fuera de `ContextoTenant::ejecutarAislado()`. Es el
mensaje que la RLS lanza cuando no hay contexto. Buscar el `tenant_id` de la
peticion.

### "La cuenta no esta asociada a ninguna inmobiliaria"

La persona entro pero su equipo de Authentik no corresponde a ninguna
inmobiliaria activa. Ver [05](05-CONFIGURACION.md).

### Todos los POST devuelven 419

El token CSRF no esta llegando. Casi siempre es que el BFF no reenvio la
cabecera `X-XSRF-TOKEN`. Ver `web/server/utils/api.ts`.

### El aislamiento falla con "NO tiene FORCE ROW LEVEL SECURITY"

Alguien creo o altero una tabla sin el `FORCE`. Vuelve a correr las migraciones.

### El tablero sale vacio

La consulta del medidor necesita historial. Con la base recien creada y sin
semilla no hay tramos. Corre `php artisan db:seed`.

### La liberacion no libera nada

Comprueba que la reserva tenga `expira_en` en el pasado **y**
`estado_contrato = 'vigente'`. La restriccion de la base impide crear una
reserva sin `expira_en`, pero una reserva vencida hace mucho ya quedo `expirada`
y no se vuelve a tocar.