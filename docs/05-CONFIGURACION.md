# 05 - Configuración

Tres cosas se configuran en una instalación: la identidad, los estados, y las
reglas del negocio. Esta es la guia de las tres.

---

## 1. Identidad (Authentik)

### 1.1 Aplicación y cliente OIDC

En Authentik:

1. Una **aplicación** de tipo `OAuth2 / OpenID Connect`.
2. Un **cliente** OIDC con:
   - Tipo: *Confidential*.
   - URI de redireccion: exactamente el `AUTHENTIK_REDIRECT_URI`.

**El cliente es exclusivo de esta aplicación.** Es lo que permite revocar el
acceso a Hefesto Inmobiliaria sin tocar las demas aplicaciones de Hefesto.

`AUTHENTIK_REDIRECT_URI` debe coincidir **carácter por carácter** con el de
Authentik. Una diferencia de barra final es la causa mas comun de un
"invalid client".

### 1.2 El prefijo de equipos

```env
INMOBILIARIA_PREFIJO_EQUIPO=HefestoInmobiliaria
```

El nombre del equipo se arma como:

```
<prefijo>:<correlacion>[:<correo>]
```

| | | |
|---|---|---|
| `HefestoInmobiliaria:sur` | Equipo de la **inmobiliaria** | Define a quien pertenece una persona |
| `HefestoInmobiliaria:sur:ana@sur.test` | Equipo de una **persona** dentro de ella | Permite una membresia por persona |

La `correlacion` (`sur`) tiene que coincidir **exactamente** con la columna
`correlacion_identidad` de la tabla `inmobiliarias`. Es la bisagra entre las dos
piezas, y si no coincide la persona entra a una pantalla que dice "tu cuenta no
pertenece a ninguna inmobiliaria".

### 1.3 Por que equipos y no el dominio del correo

Porque una inmobiliaria puede tener correos de varios dominios, y una persona
puede trabajar para dos inmobiliarias. El dominio no expresa la relacion real; el
equipo si.

### 1.4 Una persona en dos inmobiliarias

El código **no elige una**: si los equipos de la persona corresponden a dos
correlaciones distintas, el ingreso se rechaza con un 403 que lo explica.

Es una decisión. Podria haberse resuelto con un selector de inmobiliaria en el
inicio de sesión, pero la eleccion depende de como trabaje el cliente: puede ser
que se resuelva eligiendo la inmobiliaria mas reciente, que se le exija salir de un
equipo, o que se muestre un selector. **Queda anotado como decisión pendiente**,
no como algo resuelto. Ver [03](03-MULTITENANCY-Y-ROLES.md).

### 1.5 El rol por omision

Una cuenta nueva entra como **corredor**, nunca como administrador. El alcance
mas restrictivo por omision, y cambiar el rol es una accion de la
administración.

### 1.6 Rotacion de claves

Si Authentik rota sus claves de firma y los ingresos empiezan a fallar:

```bash
php artisan inmobiliaria:olvidar-cache-oidc
```

El cache del JWKS dura 24 horas por omision. Este comando es la salida de
emergencia de una rotacion.

---

## 2. Estados

Los estados son **configuración de cada inmobiliaria**, no un enum del código.
Esta pantalla la administra el jefe de la inmobiliaria.

### 2.1 Los cuatro que el motor necesita

| Clave | Para que |
|---|---|
| `disponible` | A donde vuelve una propiedad cuando se cae una reserva |
| `reservada` | El estado cuya duracion es el indicador que se vende |
| `escriturado` | Fin de una venta |
| `arrendada` | Fin de un arriendo |

Se reconocen por `clave_sistema`, **nunca por el nombre**. La semilla los crea
con nombres chilenos ("En oferta") a propósito, para que la busqueda por clave
quede probada desde el primer arranque.

**Si a una inmobiliaria le falta el estado `disponible`, no se puede liberar una
reserva.** El sistema lo dice con un error explicito en vez de dejar la propiedad
en un limbo:

> La inmobiliaria no tiene un estado con la clave de sistema disponible. Sin ese
> estado no se puede devolver una propiedad al mercado.

### 2.2 `clave_sistema` no se puede cambiar

Se asigna una vez, al crear el estado, y ya no se edita. No hay forma de
cambiarla por la API, ni siquiera mandando el mismo valor.

La razon es concreta: es la cadena por la que el motor reconoce los estados que
usa en las reglas automaticas. Si se pudiera cambiar, una reconfiguracion
rutinaria de nombres dejaria reservas sin liberar **sin avisar**.

### 2.3 Reglas de la configuración

- **Un solo estado inicial** por tipo de operación. Si hubiera dos, el alta de una
  oferta elegiria uno al azar y el otro quedaria como inicial sin usar.
- **Un nombre único** por tipo de operación: dos estados llamados "Disponible"
  harian ambigua la lectura de la linea de tiempo.
- **Los estados no se borran.** Un estado con historial es información que el
  sistema ya le mostro a alguien; borrarlo dejaria tramos huerfanos. Lo que si se
  permite es **desactivarlo**: deja de ofrecerse, sin romper nada.

### 2.4 Estados propios

Un estado con `clave_sistema` en `NULL` es configuración pura. El motor no tiene
ninguna regla que dependa de el, así que se puede llamar como quiera. La semilla
incluye uno, "En evaluacion del propietario", para que el caso este a la vista.

---

## 3. Reglas del negocio

Todas en `api/config/inmobiliaria.php`, con su variable de entorno.

### 3.1 Reserva

```env
INMOBILIARIA_RESERVA_DIAS_MAXIMOS=30
INMOBILIARIA_RESERVA_DIAS_AVISO=3
```

`DIAS_MAXIMOS` es un **tope de seguridad**: una reserva cuya fecha de vencimiento
se pasa de esto no se puede crear. Una reserva que espera medio año ya no es una
reserva.

El tope se verifica **en el alta**, en `CrearOperacionRequest`, y no solo al
liberar. Si se verificara al liberar, la propiedad ya estaria tomada desde el
principio, que es el problema que se quiere evitar.

### 3.2 Arriendo

```env
INMOBILIARIA_ARRIENDO_DIAS_AVISO=60
INMOBILIARIA_ARRIENDO_MESES_MAXIMOS=60
```

El aviso es la única ocasion en que se puede recuperar un arriendo que se va a
perder: ahi el contrato pasa a `por_renovar`.

### 3.3 Comisiones

```env
INMOBILIARIA_COMISION_VENTA=3.0
INMOBILIARIA_COMISION_ARRIENDO=4.0
```

Estos valores son la **práctica del mercado** para precargar el formulario. **No
son las reglas de calculo.** Las reglas viven en
`App\Services\Operaciones\CalculadoraDeComision` y siempre se aplican sobre el
valor pactado. Ver [02](02-MODELO-DE-DATOS.md), seccion de comisiones.

### 3.4 Alarmas del tablero

```env
INMOBILIARIA_RESERVA_DIAS_COLGADA=30
INMOBILIARIA_ARRIENDO_DIAS_SIN_RENOVAR=45
```

Desde cuantos dias una reserva se marca como "colgada". Una reserva de 200 días
es un dato; una de 12 también lo es, pero solo una de las dos merece una campana.

---

## 4. El planificador

Dos reglas automaticas, y ninguna depende de que alguien se acuerde.

| Cuando | Que corre |
|---|---|
| Cada hora | `inmobiliaria:liberar-reservas` |
| Diario | `inmobiliaria:marcar-retornos` |

Definidos en `api/routes/console.php`. En producción corre el servicio
`planificador` de `docker-compose.prod.yml`.

Cada hora y no una vez al dia porque una reserva puede vencer a las once de la
mañana y la propiedad no tiene por que quedarse tomada hasta la medianoche.

### Comprobar sin escribir

```bash
# Que reservas vencen, sin tocar nada:
php artisan inmobiliaria:liberar-reservas --simular

# Solo una inmobiliaria, para depurar:
php artisan inmobiliaria:liberar-reservas --inmobiliaria=sur --simular
```

La simulacion abre **el mismo contexto aislado** que el trabajo real. Si simular
y ejecutar usaran caminos distintos, la simulacion no serviria para anticipar el
problema.

---

## 5. Copias de seguridad y respaldos

Ver [08](08-RESPALDOS.md).

---

## 6. Variables de entorno, indice

### Aplicación

| Variable | Por omision | Que hace |
|---|---|---|
| `APP_ENV` | `local` | `local` o `production` |
| `APP_DEBUG` | `false` | **En producción, `false`** |
| `APP_TIMEZONE` | `America/Santiago` | Zona de las fechas |
| `APP_KEY` | *(vacio)* | `php artisan key:generate` |

### Base de datos

| Variable | Por omision |
|---|---|
| `DB_CONNECTION` | `pgsql` — **no cambiar** |
| `DB_HOST` | `postgres` |
| `DB_PORT` | `5432` |
| `DB_DATABASE` | `inmobiliaria` |
| `DB_USERNAME` | `inmobiliaria` |
| `DB_PASSWORD` | *(cambie-esta-clave)* |
| `DB_SSLMODE` | `prefer` |

`DB_SSLMODE=prefer` sirve dentro de la red de Compose. **Si la base queda afuera
de la instalación, use `require`**: sin cifrado, el permiso de la RLS viaja en
claro.

### Sesión

| Variable | Por omision | Nota |
|---|---|---|
| `SESSION_EXPIRACION_MINUTOS` | `120` | Una sesión abierta mucho rato es una sesión abierta a la cartera |
| `SESSION_SECURE_COOKIE` | `false` | **En producción, `true`** |

### Redis

| Variable | Por omision |
|---|---|
| `REDIS_CLIENT` | `predis` |
| `REDIS_HOST` | `redis` |
| `REDIS_DB` | `0` |
| `REDIS_CACHE_DB` | `1` |

### Authentik

| Variable | Por omision |
|---|---|
| `AUTHENTIK_ISSUER` | `https://identidad.hefesto.cl` |
| `AUTHENTIK_CLIENT_ID` | *(vacio)* |
| `AUTHENTIK_CLIENT_SECRET` | *(vacio)* |
| `AUTHENTIK_REDIRECT_URI` | `http://localhost:8000/api/v1/auth/callback` |
| `AUTHENTIK_SCOPES` | `openid profile email groups` |
| `AUTHENTIK_ALGORITMO` | `RS256` |
| `AUTHENTIK_CACHE_METADATA` | `3600` |
| `AUTHENTIK_CACHE_JWKS` | `86400` |
| `AUTHENTIK_LEEWAY` | `60` |

`groups` en los scopes no es opcional: sin el, no hay forma de resolver la
inmobiliaria. Ver la seccion 1.3.