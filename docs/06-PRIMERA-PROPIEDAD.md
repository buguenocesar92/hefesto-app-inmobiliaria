# 06 - Primera propiedad: del cero al tablero

El camino completo, en orden, para ver el producto funcionando con datos de
propiedad real. Es la guía de una tarde.

> **Estado: NO se ejecutó.** En el entorno donde se escribió este proyecto no hay
> PHP, ni Composer, ni Docker, ni Authentik. Ver
> [10](10-PRUEBAS-Y-ESTADO.md).

---

## 1. Antes de empezar

Tres cosas tienen que estar listas:

1. **La instalación**, con `php artisan migrate` aplicado. Ver
   [04](04-INSTALACION.md).
2. **Authentik**, con un cliente OIDC y el equipo de la inmobiliaria. Ver
   [05](05-CONFIGURACION.md).
3. **Estados configurados**, con los cuatro que el motor necesita
   (`disponible`, `reservada`, `escriturado`, `arrendada`).

Sin los cuatro estados, el paso 6 va a fallar con un error explicito, y esta bien
que falle: es preferible a dejar propiedades en un limbo.

---

## 2. Crear la inmobiliaria

La inmobiliaria es el tenant. Se crea desde la consola o por SQL, porque en el
sistema no hay un formulario de "registro": las instalaciones las abre Hefesto.

```sql
INSERT INTO inmobiliarias (
    id, correlacion_identidad, slug, nombre, zona_horaria, activo,
    created_at, updated_at
) VALUES (
    gen_random_uuid(),
    'sur',                       -- tiene que coincidir con el equipo de Authentik
    'inmobiliaria-sur',
    'Inmobiliaria Sur',
    'America/Santiago',
    true,
    now(), now()
);
```

El `correlacion_identidad` es la bisagra: es el texto que aparece en el nombre
del equipo de Authentik (`HefestoInmobiliaria:sur`).

---

## 3. Crear las cuentas

También sin formulario, y a propósito: una cuenta creada desde la aplicación
seria una cuenta sin identidad, que nadie podria revocar desde Authentik.

Las cuentas se crean al **primer ingreso**, siempre que la persona pertenezca al
equipo correcto. Para crear al administrador:

1. En Authentik, agregue a la persona al equipo `HefestoInmobiliaria:sur`.
2. Que entre a la aplicación.
3. Queda creada como **corredor** (el alcance mas restrictivo).
4. Cambie su rol desde administración, o por consola:

```sql
UPDATE usuarios SET rol = 'administrador' WHERE correo = 'admin@sur.test';
```

---

## 4. Cargar la primera propiedad

Desde la interfaz, o por API.

```http
POST /api/v1/propiedades
Content-Type: application/json
X-XSRF-TOKEN: <de la cookie>

{
  "direccion": "Av. Los Capitanes 4455, depto 1203, Las Condes",
  "comuna": "Las Condes",
  "tipo_propiedad": "departamento",
  "dormitorios": 4,
  "banos": 3,
  "estacionamientos": 2,
  "superficie_m2": 142.5,
  "valor_venta": 189000000
}
```

Dos cosas que la validación comprueba y que conviene entender antes:

- **Un corredor solo puede registrar en su propia cartera.** Si manda un
  `corredor_id` que no es el suyo, la peticion se rechaza.
- **En el alta hace falta al menos un precio** (venta o arriendo). Una propiedad
  sin precio no tiene nada que ofrecer y no genera ninguna oferta posible.

### La propiedad todavia no tiene estado

Esto es lo que mas sorprende la primera vez, y es correcto. La propiedad es el
inmueble; el estado vive en la oferta, y la oferta se crea en el paso siguiente.

---

## 5. Crear la oferta

Una oferta por tipo de operación. Esta propiedad se ofrece en venta:

```http
POST /api/v1/ofertas

{
  "propiedad_id": "<id>",
  "tipo_operacion": "venta",
  "valor_lista": 189000000
}
```

Al crearse, la oferta:

1. Toma el estado **inicial** de la inmobiliaria.
2. **Arranca su historial**: el primer tramo, con `estado_anterior_id` en `NULL`.

Sin ese primer tramo no habria forma de medir permanencia, y el producto se
apoia desde ese momento.

### Venta y arriendo a la vez

Para ofrecerla también en arriendo, cree una segunda oferta con
`"tipo_operacion": "arriendo"`. A partir de ahi la propiedad tiene **dos lineas de
tiempo independientes**, que es exactamente lo que el negocio pide y lo que un
campo único no puede expresar.

---

## 6. El cambio de estado, que es donde se ve el producto

```http
POST /api/v1/ofertas/<id>/estado

{
  "estado_id": "<id del estado reservada>",
  "motivo": "El cliente dejo seña, se reserva por 15 dias."
}
```

Que hace el sistema:

1. **Cierra** el tramo abierto: escribe `hasta` y `duracion_dias`.
2. **Abre** un tramo nuevo con `hasta` en `NULL`.
3. **Actualiza** el estado de la oferta, en la misma transacción.

Las tres juntas o ninguna. Si la tercera se hiciera sola, la propiedad mostraria
un estado que no tiene historia.

Ahora mire:

```
GET /api/v1/ofertas/<id>/historial
```

Y el tablero, que ya muestra los dias en el estado anterior.

---

## 7. Cerrar una operación: la reserva

```http
POST /api/v1/operaciones

{
  "oferta_id": "<id>",
  "cliente_id": "<id>",
  "tipo": "reserva",
  "valor_pactado": 180000000,
  "porcentaje_comision": 3.0,
  "expira_en": "2026-11-30T23:59:00Z"
}
```

Compruebe lo que paso:

- La comision se calculo sobre **180.000.000**, no sobre los 189.000.000 de
  lista. Son 5.400.000, no 5.670.000.
- La oferta paso a **reservada**, con su tramo de historial.
- `expira_en` tiene que estar, y no puede ser mas de
  `INMOBILIARIA_RESERVA_DIAS_MAXIMOS` dias en el futuro.

---

## 8. Ver la reserva vencida liberarse sola

Para verlo sin esperar, deje la reserva con `expira_en` en el pasado y corra:

```bash
php artisan inmobiliaria:liberar-reservas --simular
php artisan inmobiliaria:liberar-reservas
```

Que deberia pasar:

1. La operación queda `expirada`.
2. La oferta vuelve al estado **disponible**, resuelta por `clave_sistema`, no
   por nombre.
3. En el historial aparece un tramo **automático** (sin usuario), con el motivo
   "Liberacion automática de reserva vencida" y con la duracion de la reserva
   ya medida.

Ese tercer punto es el que mas importa. Si la liberacion no se anotara en el
historial, el tiempo que la propiedad estuvo reservada no existiría para nadie, y
el tablero mostraria un tiempo de permanencia mas corto que el real.

---

## 9. El tablero

```http
GET /api/v1/tablero/permanencia?tipo_operacion=venta
GET /api/v1/tablero/en-curso?tipo_operacion=venta
```

Le deberian salir los cuatro estados, con:

| Columna | Que es |
|---|---|
| Mediana | El caso tipico. No se distorce con una reserva eterna |
| p90 | El mal caso. Si te pasas de tu propio p90, es anormal para tu negocio |
| Promedio | Para comparar carteras del mismo tamano |
| En curso | Cuantas hay HOY en ese estado |

Un estado sin historial aparece con todo en cero, no desaparece. Un tablero que
escondiera un estado porque nadie llego a el haria creer que el estado no existe.

---

## 10. Comprobar el aislamiento

Esto es lo que hay que revisar antes de cargar datos de verdad.

### Con dos cuentas

1. Entre como `ana@sur.test` y anote una propiedad.
2. Entre como `beto@sur.test`.
3. La propiedad de Ana **no aparece** en el listado de Beto.
4. Si abre la URL directa, recibe **404**, no 403.

El 404 importa: un 403 confirmaria que la propiedad existe.

### Con dos inmobiliarias

Es lo mas cercano a una fuga real, y conviene probarlo:

1. Cree una segunda inmobiliaria.
2. Cargue una propiedad en ella.
3. Desde la cuenta de la primera, pida esa propiedad.
4. **404**, y la consulta por API no devuelve nada.

### En la consola

```bash
php artisan inmobiliaria:verificar-aislamiento
```

Sale con código cero, o dice exactamente que fallo.

---

## 11. Un dia de trabajo normal

Para entender que hace el sistema solo, con todo ya cargado:

| Cuando | Que pasa |
|---|---|
| Un corredor entra una propiedad | Queda en "En oferta", con su primer tramo |
| Un cliente visita | Queda la visita con su resultado |
| El cliente deja seña | La oferta pasa a "Reservada" con fecha de vencimiento |
| Pasa la fecha y nadie confirma | **Sola**: vuelve a "En oferta", anotada como cambio del sistema |
| El arriendo se acerca al termino | Pasa a "Por renovar", que es la única occasion de recuperarlo |
| El arriendo termina sin renovar | **Solo**: vuelve al mercado, anotada como retorno |
| El jefe abre el tablero | Ve cuanto tardo cada estado, y cuales se estan colgando |

Lo único que el sistema hace solo son esas dos reglas de vencimiento. Todo lo
demas lo hace una persona, y queda dicho quien y por que.