# Hefesto Inmobiliaria

Aplicación propia de **Hefesto Studio** para inmobiliarias: gestión de
propiedades, ventas y arriendos.

Software original de Hefesto. No es un despliegue de una herramienta de terceros.

---

## El problema que resuelve

Una inmobiliaria **no vende, ofrece**.

Una propiedad no esta "vendida" en un momento: va cambiando de estado muchas
veces. Disponible, reservada, escriturado, y de vuelta al mercado. Y lo que
realmente pierde plata no es el estado: es **la duracion de cada estado**.

> Una propiedad que estuvo reservada ocho meses es una propiedad que estuvo ocho
> meses sin vender.

Esa cifra no existe hoy en ninguna parte. No esta en el CRM, no esta en la
planilla, no esta en la bolsa de propiedades. **Ese es el producto**: medir y
mostrar cuanto tiempo lleva cada propiedad en cada estado.

---

## La idea que sostiene todo

**La propiedad no tiene estado. La oferta si.**

Una propiedad puede estar **en venta y en arriendo al mismo tiempo**, y puede
pasar de una a otra varias veces. Si el estado fuera una columna de la
propiedad, habria que elegir una, y la otra se perderia.

Así que existe `ofertas`: una por tipo de operación, con su propio estado y su
propia linea de tiempo.

```
Propiedad 1458
  - Oferta VENTA     -> "En oferta" desde 2026-01-12    (214 dias)
  - Oferta ARRIENDO  -> "Arrendada" desde 2026-03-01    (156 dias)
```

Y `cambios_estado` guarda **cada tramo**, que es lo que hace medible el negocio:

| | |
|---|---|
| `desde` | Cuando entro al estado |
| `hasta` | Cuando salio. `NULL` significa que sigue ahi |
| `duracion_dias` | Se escribe **al cerrar**, una vez |

`hasta IS NULL` significa exactamente "el estado actual". Esa convencion hace
que la linea de tiempo se lea sin lógica.

Y un disparador de PostgreSQL **impide modificar el `desde` de un tramo ya
cerrado**. Un promedio de permanencia que uno puede ajustar para que salga mejor
no es una medida.

---

## Las reglas que corren solas

Ninguna depende de que alguien se acuerde.

| Regla | Que hace |
|---|---|
| **La reserva vencida se libera sola** | Si nadie confirma antes de la fecha, la propiedad vuelve al mercado, anotada como cambio del sistema |
| **El arriendo terminado se devuelve** | Un arriendo que termina sin renovar es un **retorno**, no un evento neutro |

La primera importa mas de lo que parece: si el sistema no libera, la
inmobiliaria pierde ventas por inercia, y se pierden hacia otro sin que nada lo
avise.

---

## El tablero

Por estado y por tipo de operación, tres números, porque un promedio solo engana:

| | Que es |
|---|---|
| **Mediana** | El caso tipico. No se distorce con una reserva eterna |
| **p90** | El mal caso. Si te pasas de tu propio p90, es anormal **para tu negocio** |
| **En curso** | Cuantas hay hoy en ese estado, y la mas antigua |

Si cinco reservas duraron 10 dias y una duro 400, el promedio da 78 y no describe
a nadie. La mediana da 12, y esa es la cifra que sirve.

---

## Aislamiento entre inmobiliarias

Se reusa **tal cual** el patron documentado en
`apps/portal-autoservicio/docs/03-MULTITENANCY.md`: columna `tenant_id` mas Row
Level Security de PostgreSQL.

- `WITH CHECK` impide escribir en nombre de otra inmobiliaria.
- `FORCE ROW LEVEL SECURITY` para que la política aplique también al dueno de la
  tabla.
- Contexto con **alcance de transacción** (`set_config(..., true)`), para que el
  worker de colas no arrastre el tenant de otra inmobiliaria.
- La función de contexto **falla cerrado**: sin tenant, lanza excepcion.

Y `inmobiliaria:verificar-aislamiento` frena el despliegue si algo falta.

Dentro del tenant hay un segundo filtro: **la cartera**. Un corredor ve sus
propiedades y sus clientes; el administrador ve la casa entera.

---

## Las comisiones

Se calculan sobre el **valor pactado**, nunca sobre el precio de lista de la
ficha.

Es el error clasico de este rubro, así que la regla vive en un solo lugar
(`CalculadoraDeComision`) y hay una prueba que la fija usando **dos valores
distintos**, porque un caso con lista igual a pactado no detectaria que alguien
cambio la base del calculo.

---

## Stack

| | |
|---|---|
| API | Laravel 13 con PostgreSQL (RLS) |
| Interfaz | Nuxt 4 con render en el servidor |
| Cache y colas | Redis |
| Identidad | Authentik (OIDC) |
| Despliegue | Docker Compose con versiones fijadas |

---

## Estructura

```
apps/inmobiliaria/
├── README.md
├── docs/       01 arquitectura ... 10 pruebas y estado
├── api/        Laravel
├── web/        Nuxt
├── deploy/     Dockerfiles, Compose, Caddy, entrypoint
└── .env.example
```

Empiece por **[docs/01-ARQUITECTURA.md](docs/01-ARQUITECTURA.md)**.

---

## Levantar

```bash
cd apps/inmobiliaria

cp .env.example .env
cp api/.env.example api/.env
cp web/.env.example web/.env

# Edite api/.env: DB_PASSWORD, APP_KEY, credenciales de Authentik.

docker compose -f deploy/docker-compose.dev.yml up --build
```

Despues:

```bash
docker compose -f deploy/docker-compose.dev.yml exec api php artisan key:generate
docker compose -f deploy/docker-compose.dev.yml exec api php artisan inmobiliaria:verificar-aislamiento
```

Detalle completo en **[docs/04-INSTALACION.md](docs/04-INSTALACION.md)**.

---

## Estado honesto

> # Código escrito, sin ejecutar

En el entorno donde se construyo este proyecto **no habia PHP, ni Composer, ni
Docker, ni PostgreSQL**.

- Las 13 migraciones **no se aplicaron**.
- Las ~39 pruebas **no se ejecutaron**.
- La interfaz **no se compilo**.
- **Ninguna migración fue probada. Ninguna prueba fue corrida.**

Lo único que se ejecuto de verdad fue `bash -n` sobre el script de arranque
(exit 0) y un barrido de caracteres fuera del español en dos pasadas.

**Advertencia**: en SQLite las pruebas de aislamiento pasarían **sin comprobar
nada**, porque no hay Row Level Security. Un "todo verde" sobre SQLite no
significa nada.

Y hay un pendiente **bloqueante antes de producción**: la verificación
criptografica de la firma del `id_token` no esta implementada. El metodo lanza una
excepcion en vez de fingir que verifica, porque un metodo que devolviera claims
sin comprobar la firma pareceria funcionar y el fallo seria invisible.

Todo esto, separando lo probado de lo no probado, esta en
**[docs/10-PRUEBAS-Y-ESTADO.md](docs/10-PRUEBAS-Y-ESTADO.md)**.

---

## Documentos

| | |
|---|---|
| [01](docs/01-ARQUITECTURA.md) | Arquitectura y las tres ideas que sostienen el sistema |
| [02](docs/02-MODELO-DE-DATOS.md) | El modelo de datos, tabla por tabla |
| [03](docs/03-MULTITENANCY-Y-ROLES.md) | Aislamiento entre inmobiliarias y roles |
| [04](docs/04-INSTALACION.md) | Cómo se levanta |
| [05](docs/05-CONFIGURACION.md) | Authentik, estados y reglas |
| [06](docs/06-PRIMERA-PROPIEDAD.md) | Del cero al tablero |
| [07](docs/07-SEGURIDAD-Y-LEY-19628.md) | Seguridad y datos personales |
| [08](docs/08-RESPALDOS.md) | Respaldos y restauración |
| [09](docs/09-API.md) | La superficie de la API |
| [10](docs/10-PRUEBAS-Y-ESTADO.md) | **Qué se ejecutó y qué no** |

---

Software original de Hefesto Studio, propiedad de Hefesto.