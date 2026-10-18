# 09 - API

Toda la superficie esta bajo `/api/v1`, en un solo archivo
(`api/routes/web.php`), versionada en una sola linea.

---

## 1. Convenciones

### 1.1 Sesión, no token

La API **no usa token**. La interfaz (Nuxt) y la API se sirven en el mismo origen,
el navegador manda la cookie y el token CSRF viaja en la cabecera
`X-XSRF-TOKEN`. Ver [01](01-ARQUITECTURA.md).

```http
Cookie: inmobiliaria_sesion=...; XSRF-TOKEN=...
X-XSRF-TOKEN: <valor de XSRF-TOKEN>
```

Sin la cabecera en los metodos que modifican datos, la respuesta es **419**.

### 1.2 Como se responde un error

| Código | Cuando |
|---|---|
| **403** | Permiso de rol. El middleware `admin` lo corta antes del controlador |
| **404** | La Policy rechazo. **Un 403 confirmaria que el recurso existe** |
| **419** | Falta o expiro el token CSRF |
| **422** | Falla la validación. Viene con el detalle por campo |

Formato:

```json
{ "mensaje": "Revisa los datos ingresados.", "errores": { "expira_en": ["..."] } }
```

Un 404 no distingue entre "no existe" y "es de otra cartera". Esa indistinguibilidad
es deliberada: distinguirlas confirmaria que el recurso existe.

### 1.3 Convenios de los datos

| | |
|---|---|
| Fechas | ISO 8601 con zona (`2026-10-17T14:30:00-03:00`) |
| Fechas sin hora (`fecha_inicio`, `fecha_termino`) | `AAAA-MM-DD` |
| Dinero | Pesos enteros, sin decimales |
| Ids | UUID v4 |
| `null` vs ausente | `null` es "se consulto y no hay"; ausente es "no se consulto" |

### 1.4 Onde decide cada permiso

Las rutas declaran **que** recurso se toca. Quien lo puede hacer se decide en
`App\Policies`. El control fino no esta en las rutas.

---

## 2. Autenticación

| | |
|---|---|
| `GET /api/v1/auth/login` | Redirige a Authentik |
| `GET /api/v1/auth/callback` | Callback: verifica y abre la sesión |
| `POST /api/v1/auth/logout` | Cierra la sesión |
| `GET /api/v1/auth/usuario` | Quien soy, con rol y alcance |

```http
GET /api/v1/auth/usuario
```

```json
{
  "datos": {
    "id": "...",
    "nombre": "Ana Rojas",
    "correo": "ana@sur.test",
    "rol": "corredor",
    "es_administrador": false,
    "cartera": "...",
    "inmobiliaria": { "id": "...", "nombre": "Inmobiliaria Sur", "slug": "inmobiliaria-sur" }
  }
}
```

`cartera` viene en `null` para el administrador: es el único que ve toda la
inmobiliaria.

---

## 3. Tablero

Lo primero que se mira, y lo que justifica la aplicación.

### `GET /api/v1/tablero/permanencia`

| Parámetro | | |
|---|---|---|
| `tipo_operacion` | **obligatorio** | `venta` o `arriendo` |
| `comuna` | opcional | Filtra por comuna |
| `corredor_id` | opcional | **Solo administrador.** Un corredor no puede pedir el de otro |

```json
{
  "tipo_operacion": "venta",
  "alcance": "inmobiliaria",
  "datos": [
    {
      "estado_id": "...",
      "nombre": "Reservada",
      "color": "#b8860b",
      "tramos_cerrados": 24,
      "promedio_dias": 75.8,
      "mediana_dias": 12.5,
      "p90_dias": 180.0,
      "maximo_dias": 400,
      "en_curso": 3,
      "dias_del_mas_antiguo": 210
    }
  ]
}
```

### Por que los tres números

Un promedio solo engana. Si cinco reservas duraron 10 dias y una duro 400, el
promedio da 78 y no describe a nada:

| | Que es |
|---|---|
| `mediana_dias` | El caso tipico. No se distorce con un caso raro |
| `p90_dias` | El mal caso. Si te pasas de tu propio p90, es anormal **para tu negocio** |
| `promedio_dias` | Para comparar carteras del mismo tamano |

Un estado sin historial aparece con `tramos_cerrados: 0` y los dias en `null`.
**No desaparece**: un tablero que escondiera un estado haria creer que no existe.

`null` y `0` no son lo mismo: `null` es "no hay casos"; `0` es "los casos duran
cero".

### `GET /api/v1/tablero/en-curso`

| Parámetro | | |
|---|---|---|
| `tipo_operacion` | **obligatorio** | |
| `comuna`, `estado_id` | opcional | |
| `limite` | opcional | 1 a 200, por omision 50 |

Viene **ordenado de mas antiguo a mas reciente**. El orden es el diagnóstico: la
primera fila es, casi siempre, una reserva olvidada.

```json
{
  "total": 3,
  "datos": [
    {
      "oferta_id": "...",
      "propiedad_id": "...",
      "estado_id": "...",
      "direccion": "Av. Providencia 1940, of. 803",
      "comuna": "Providencia",
      "corredor": "Ana Rojas",
      "estado": "Reservada",
      "estado_color": "#b8860b",
      "dias_en_estado": 210,
      "en_curso_desde": "2026-03-11T00:00:00-03:00"
    }
  ]
}
```

---

## 4. Propiedades

| | |
|---|---|
| `GET /api/v1/propiedades` | Listado, filtrado por cartera |
| `POST /api/v1/propiedades` | Alta |
| `GET /api/v1/propiedades/{id}` | Ficha |
| `PATCH /api/v1/propiedades/{id}` | Edicion |

Parámetros del listado: `comuna`, `tipo_propiedad`, `tipo_operacion`,
`corredor_id`, `activa`, `buscar`, y paginacion.

**`corredor_id` se ignora para un corredor.** Pide su cartera de todas maneras.
Que el parámetro exista pero no se respete para quien no es administrador es
intencional: aceptarlo seria darle a un corredor el inventario entero.

### La ficha trae los dos estados

```json
{
  "datos": {
    "id": "...",
    "direccion": "Av. Los Capitanes 4455, depto 1203",
    "comuna": "Las Condes",
    "valor_venta": 189000000,
    "valor_arriendo": null,
    "estado_venta": {
      "oferta_id": "...",
      "estado_nombre": "Reservada",
      "estado_color": "#b8860b",
      "dias_en_estado": 210,
      "valor_lista": 189000000
    },
    "estado_arriendo": null
  }
}
```

`estado_arriendo: null` **no es un dato faltante**: es la respuesta a "esta en
arriendo?". Por eso se distingue de "esta en arriendo y no sabemos en que estado",
que seria un objeto con los datos en `null`.

---

## 5. Ofertas

| | |
|---|---|
| `GET /api/v1/ofertas` | Listado |
| `POST /api/v1/ofertas` | Alta. **Arranca el historial** |
| `GET /api/v1/ofertas/{id}` | Ficha |
| `PATCH /api/v1/ofertas/{id}` | Precio, visibilidad. **No el estado** |

Al crear una oferta se toma el estado **inicial** del tipo de operación y se
escribe el primer tramo. Si no hay un estado inicial configurado, el alta falla
con un 422 explicito: es mejor no tener oferta que tener una cuya linea de tiempo
arranca a destiempo.

`PATCH` **no** cambia el estado. Para eso esta la ruta siguiente, y la razon es
que escribir el historial necesita su propio camino.

### `POST /api/v1/ofertas/{id}/estado`

```http
{
  "estado_id": "...",
  "motivo": "El cliente dejo seña, se reserva por 15 dias."
}
```

```json
{
  "mensaje": "Estado actualizado.",
  "tramo": { "...": "el tramo nuevo" },
  "oferta": {
    "estado_nombre": "Reservada",
    "estado_color": "#b8860b",
    "dias_en_estado": 0
  }
}
```

Comprobaciones que hace, y por que:

| Comprobacion | Por que |
|---|---|
| El estado es de esta inmobiliaria | Sin esto, un id ajeno daria 422 y confirmaria que existe |
| El estado aplica a este tipo de operación | No se puede pasar una oferta de arriendo a "escriturado" |
| No es el estado en que ya esta | No produce historial, produce ruido |

El `usuario_id` del tramo sale de la sesión, **nunca del cuerpo de la peticion**.
Si viniera del cliente, cualquiera podria escribir en la historia que el cambio
lo hizo un colega.

### `GET /api/v1/ofertas/{id}/historial`

```json
{
  "oferta_id": "...",
  "tipo_operacion": "venta",
  "dias_en_estado_actual": 210,
  "datos": [
    {
      "estado_anterior": { "nombre": "En oferta", "color": "#2f6f4e" },
      "estado_nuevo": { "nombre": "Reservada", "color": "#b8860b" },
      "desde": "2026-03-11T00:00:00-03:00",
      "hasta": null,
      "dias": 210,
      "duracion_final": null,
      "abierto": true,
      "es_automatico": false,
      "usuario": { "nombre": "Ana Rojas" },
      "motivo": "El cliente dejo seña"
    }
  ]
}
```

| Campo | Que es |
|---|---|
| `dias` | Duracion si el tramo esta cerrado; tiempo transcurrido si sigue abierto |
| `duracion_final` | `null` mientras el tramo este abierto |
| `abierto` | `true` si el tramo es el estado actual |
| `es_automatico` | `true` si lo hizo el sistema, no una persona |

`es_automatico` viaja explicito porque la linea de tiempo tiene que poder decir
"liberada por vencimiento" **sin suponer que fue decisión de un corredor**, y no
puede deducirlo de que `usuario` venga en `null`.

---

## 6. Clientes y solicitudes

| | |
|---|---|
| `GET /api/v1/clientes` | Listado, por cartera |
| `POST /api/v1/clientes` | Alta. **`consentimiento_contacto` es obligatorio** |
| `GET /api/v1/clientes/{id}` | Ficha con sus solicitudes |
| `PATCH /api/v1/clientes/{id}` | Edicion. **No cambia el corredor** |
| `GET /api/v1/clientes/{id}/solicitudes` | Solicitudes del cliente |
| `POST /api/v1/clientes/{id}/solicitudes` | Nueva solicitud |
| `GET /api/v1/solicitudes/{id}` | Ficha |
| `PATCH /api/v1/solicitudes/{id}` | Edicion |
| `POST /api/v1/solicitudes/{id}/propiedades` | **Proponer una propiedad** |

### Un cliente tiene varias solicitudes activas

No hay una sola ficha de busqueda por cliente. La solicitud es una fila, y un
cliente puede tener varias a la vez: una de arriendo en un sitio y otra de venta
en otro.

### Una solicitud tiene varias propiedades

```http
POST /api/v1/solicitudes/{id}/propiedades

{ "propiedad_id": "..." }
```

La escritura va con `insert` y no con `attach`, porque el pivote tiene datos
propios: cuando se propuso, quien la propuso y como le fue. Sin eso no se puede
responder "el cliente no vio este depto porque se lo filtramos".

Rechaza con 422 si la propiedad ya fue propuesta a esa solicitud, o si no tiene
oferta del tipo que pide la solicitud.

> **La propuesta no exige que la propiedad sea del mismo corredor.** El caso
> normal de una inmobiliaria es que un corredor le muestre a su cliente un
> inmueble que capturo un colega. Filtrar aca impediria el caso mas comun.

---

## 7. Visitas

| | |
|---|---|
| `GET /api/v1/visitas` | Listado |
| `POST /api/v1/visitas` | Alta |
| `GET /api/v1/visitas/{id}` | Ficha |
| `PATCH /api/v1/visitas/{id}` | **Registrar el resultado** |

Parámetros del listado: `desde`, `hasta`, `cliente_id`, `propiedad_id`, `estado`.

Cuando una visita se marca `realizada`, el pivote de la propuesta asociada pasa a
`visitada`. No es una duplicación: son dos lecturas del mismo hecho, y dejarlas
desalineadas produce un informe de "propuestas" que no coincide con el de
"visitas".

Rechaza con 422 si la propiedad **no tiene ofertas activas**: una visita a algo
que ya no esta en el mercado casi siempre es un error de agenda.

---

## 8. Operaciones

| | |
|---|---|
| `GET /api/v1/operaciones` | Listado |
| `POST /api/v1/operaciones` | Alta de reserva o arriendo |
| `GET /api/v1/operaciones/{id}` | Ficha |
| `POST /api/v1/operaciones/{id}/confirmar` | **Solo administrador** |
| `POST /api/v1/operaciones/{id}/renovar` | **Solo administrador** |

### Crear una reserva

```http
POST /api/v1/operaciones

{
  "oferta_id": "...",
  "cliente_id": "...",
  "tipo": "reserva",
  "valor_pactado": 180000000,
  "porcentaje_comision": 3.0,
  "expira_en": "2026-11-30T23:59:00Z"
}
```

| Campo | Para |
|---|---|
| `valor_pactado` | **De aquí sale la comision**, no del precio de lista |
| `porcentaje_comision` | El porcentaje. El **monto no se recibe**: se calcula |
| `expira_en` | **Obligatorio** en una reserva, prohibido en un arriendo |
| `fecha_inicio` y `fecha_termino` | Obligatorios en un arriendo, prohibidos en una reserva |

La comision se calcula sobre `valor_pactado` y **no se acepta desde la peticion**.
Recibir el monto seria dejar que cada quien invente su propia comision.

El tope `INMOBILIARIA_RESERVA_DIAS_MAXIMOS` se verifica **en el alta**: una
reserva con dos años de vencimiento dejaria la propiedad tomada dos años sin que
nadie la vuelva a mirar.

Crear una operación **mueve el estado de la oferta** al que corresponde
("reservada" o "arrendada"), y ese movimiento escribe el historial.

### Los valores de dinero

```json
{
  "id": "...",
  "tipo": "reserva",
  "estado_contrato": "vigente",
  "valor_pactado": null,
  "valor_lista_oferta": null,
  "porcentaje_comision": null,
  "comision_monto": null,
  "valor_lista_propiedad": 189000000,
  "expira_en": "2026-11-30T23:59:00Z",
  "dias_para_vencer": 44
}
```

Para un **corredor**, las cuatro primeras cifras vienen en `null`. Es
`OperacionPolicy::verDinero`.

Van en `null` y no en un 403 porque el corredor **necesita** ver la operación:
tiene que saber si su reserva esta confirmada. Un 403 sobre un recurso que ya se
esta mostrando obliga al cliente a tratar un caso especial.

`valor_lista_propiedad` si se muestra siempre: esta en la ficha y no es sensible.

### Los estados del contrato

| | |
|---|---|
| `vigente` | Activa. Puede vencer |
| `expirada` | **Una reserva que se cayo por no confirmar a tiempo.** Problema del PROCESO |
| `anulada` | Alguien la dejo sin efecto antes de vencer. Es una DECISIÓN |
| `confirmada` | El cliente acepto |
| `por_renovar` | El arriendo se acerca al termino |
| `renovada` | Se extendio |
| `retornado` | **Termino sin renovar. Es un retorno, no un evento neutro** |
| `terminada` | Fin del contrato |

`expirada` y `anulada` estan separadas a propósito: el tablero las cuenta
distinto.

---

## 9. Administración

Todas bajo el middleware `admin`: 403 si no eres administrador.

| | |
|---|---|
| `GET /api/v1/admin/estados` | Listado, con los que el motor necesita |
| `POST /api/v1/admin/estados` | Crear |
| `GET /api/v1/admin/estados/{id}` | Ficha |
| `PATCH /api/v1/admin/estados/{id}` | Editar |
| `GET /api/v1/admin/usuarios` | Listado |
| `POST /api/v1/admin/usuarios` | Crear |
| `GET /api/v1/admin/usuarios/{id}` | Ficha |
| `PATCH /api/v1/admin/usuarios/{id}` | Rol y suspension |

### `clave_sistema` no se puede editar

Se asigna una vez, al crear el estado. **No hay forma de cambiarla por la API, ni
siquiera mandando el mismo valor.**

Es la cadena por la que el motor reconoce los estados que usa en las reglas
automaticas. Si se pudiera cambiar, una reconfiguracion rutinaria de nombres
dejaria reservas sin liberar **sin avisar**.

Los estados **no se borran**: `EstadoPolicy::delete()` devuelve `false` siempre.
Lo que si se permite es desactivarlos.

### El último administrador

`PATCH /usuarios/{id}` rechaza con 422 si el cambio dejaria la inmobiliaria sin
administradores activos. Una inmobiliaria sin administrador no tiene quien
administre los estados ni las comisiones, y el arreglo seria entrar a la base a
mano.

---

## 10. Salud

```http
GET /api/v1/salud
```

Sin autenticación. No toca ninguna tabla protegida: si lo hiciera,
`public.app_tenant_id()` lanzaria excepcion. El aislamiento falla cerrado, nunca
abierto.