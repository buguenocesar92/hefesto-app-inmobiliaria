# 02 - Modelo de datos

Trece tablas. Once tienen `tenant_id` y Row Level Security; las dos ultimas son
de union. Este documento explica **por que** esta cada una así, porque en este
dominio casi todas las decisiones de esquema tienen una consecuencia de negocio
detras.

---

## 1. El mapa

```
inmobiliarias  (tenant, SIN RLS)
     |
     +-- usuarios        corredores y administracion
     +-- estados         catalogo configurable de estados
     +-- propiedades     el inmueble, sin estado
     |      +-- fotos_propiedad
     |      +-- solicitud_propiedad (union) -- solicitudes -- clientes
     |      +-- visitas
     +-- ofertas         UNA por tipo de operacion. AQUI vive el estado
     |      +-- cambios_estado   EL HISTORIAL. Solo agregar.
     |      +-- operaciones       reserva o arriendo
```

Lo que hay que mirar primero: **`propiedades` no tiene columna de estado**, y
**`cambios_estado` solo se agrega**.

---

## 2. `inmobiliarias` — el tenant

El identificador del cliente de esta aplicación.

**No lleva Row Level Security**, a propósito: se consulta en el login, cuando
todavia no hay sesión y por lo tanto no hay `app.tenant_id` que poner. Es la
misma excepcion consciente y las mismas dos compensaciones que toma
`apps/portal-autoservicio`; ver [03](03-MULTITENANCY-Y-ROLES.md).

`correlacion_identidad` es el texto que aparece en el nombre del equipo de
Authentik: `<prefijo>:<correlacion>`. Es la bisagra entre las dos piezas.

---

## 3. `propiedades` — el inmueble, sin estado

Direccion, comuna, caracteristicas, y los precios de lista.

### Por que el estado NO esta aca

Una propiedad puede estar en venta y en arriendo a la vez. Con una sola columna
de estado habria que elegir una, y la otra se perderia. Ademas, "llevo 40 dias
en venta y 200 en arriendo" no se puede expresar en una fila.

El estado va en `ofertas`, y hay una fila por mercado.

### `valor_venta` y `valor_arriendo` son precios de LISTA

No son el valor pactado. El pactado vive en `operaciones.valor_pactado`.

La diferencia importa por dos razones: las comisiones se calculan sobre el
pactado, y el desvio entre lista y pactado es información de negocio (un
6% por debajo de la lista es normal; un 30% es una propiedad mal avaluada).

---

## 4. `ofertas` — la presencia comercial

Una oferta por propiedad y por tipo de operación. La restriccion
`uq_ofertas_propiedad_tipo` lo garantiza.

**Esta es la tabla que hace posible "venta y arriendo a la vez"** sin que una
pise a la otra.

### Los dos campos desnormalizados

`estado_id` y `estado_desde` son una COPIA del último tramo de
`cambios_estado`. La verdad esta ahi; aca esta el resumen.

La razon para duplicarlos es concreta: el tablero tiene que responder "cuantas
propiedades hay en estado reservada" sobre decenas de miles de filas y con
indice. Calcularlo con `MAX(fecha)` por oferta seria un agrupado sobre toda la
tabla en cada carga de pantalla.

La desincronizacion **no se acepta en silencio**:
`tests/Feature/Estados/PermanenciaEnEstadosTest.php` compara las dos copias y
falla si divergen. Si la copia es lo que se muestra, tiene que ser la que se
verifica.

---

## 5. `cambios_estado` — el corazon

Solo agregar. Nunca se actualiza para corregirla y nunca se borra.

| Columna | Que guarda |
|---|---|
| `oferta_id` | A que oferta pertenece el tramo |
| `estado_anterior_id` | De donde sale. `NULL` solo en el primer tramo |
| `estado_nuevo_id` | A donde entra |
| `desde` | Cuando entro |
| `hasta` | Cuando salio. `NULL` = sigue ahi |
| `duracion_dias` | Se escribe **al cerrar**, una vez |
| `usuario_id` | Quien lo hizo. `NULL` = lo hizo el sistema |
| `motivo` | Por que |

### Tres restricciones que hacen que el dato sea confiable

**1. Solo un tramo abierto por oferta**

```sql
CREATE UNIQUE INDEX uq_cambios_oferta_tramo_abierto
    ON cambios_estado (oferta_id) WHERE hasta IS NULL;
```

Si hubiera dos, "el estado actual" seria ambiguo y el tablero contaria la
propiedad dos veces.

**2. Cerrado y medido, o nada**

```sql
CHECK (
    (hasta IS NULL AND duracion_dias IS NULL)
    OR (hasta IS NOT NULL AND duracion_dias IS NOT NULL)
)
```

Un tramo cerrado sin su duracion seria un periodo invisible para el informe: la
propiedad habria estado ahi, y el tablero no lo sabria.

**3. La historia no se reescribe**

El disparador `trg_cambios_estado_no_reescribir` impide cambiar el `desde` de un
tramo ya cerrado.

```sql
IF OLD.hasta IS NOT NULL AND (NEW.desde IS DISTINCT FROM OLD.desde) THEN
    RAISE EXCEPTION 'No se puede modificar el inicio de un tramo ya cerrado';
```

El escenario real no es un atacante: es un corredor que entra un dia tarde a
cargar una reserva y quiere que el número salga mejor. El disparador existe para
que esa intencion no dependa de la buena fe.

### Por que `duracion_dias` se guarda y no se deriva

Es derivable (`hasta - desde`) y podria haber quedado fuera. Se guarda igual:

- El calculo se hace **una vez**, al cerrar el tramo, con el reloj ya resuelto.
  Consultarlo de nuevo obliga a repetirlo para cada fila del informe.
- Un informe que cambia de resultado porque el reloj del servidor se movio no
  sirve para nada.

---

## 6. `estados` — configuración del tenant, no un enum

Los estados son filas que cada inmobiliaria configura. Un enum de PHP no serviria:
una inmobiliaria que llama "en oferta" a lo que otra llama "disponible" tiene que
poder decirlo, y tiene que poder agregar un estado propio sin que Hefesto libere
una versión.

### `clave_sistema`: el puente con el motor

Hay cuatro estados que el motor **necesita** reconocer, porque de ellos dependen
las reglas automaticas:

| Clave | Para que sirve |
|---|---|
| `disponible` | Estado al que vuelve una propiedad cuando se cae una reserva |
| `reservada` | El estado cuya duracion es el indicador que el producto vende |
| `escriturado` | Fin de una venta |
| `arrendada` | Fin de un arriendo |

El resto de los estados van con `clave_sistema` en `NULL`: son configuración, y el
motor no tiene ninguna regla que dependa de ellos.

La busqueda es **por clave, nunca por nombre**. Si fuera por nombre, cambiar
"Disponible" por "En oferta" en la pantalla de administración dejaria reservas
sin liberar sin avisar. Y la semilla crea justamente los estados con nombres
chilenos ("En oferta") para que la busqueda por clave quede probada desde el
primer arranque.

### `tipo_operacion`: 'ambas', 'venta' o 'arriendo'

Es lo que permite que exista "escriturado" sin que aparezca en el flujo de
arriendo.

---

## 7. La relacion de varios a varios entre solicitudes y propiedades

El enunciado del negocio tiene dos hechos que se contradicen si se simplifica:

- Un cliente tiene **varias solicitudes activas** a la vez.
- Una solicitud puede requerir **varias propiedades**.

Si se metiera `cliente_id` en `propiedades`, una propiedad con cuatro
solicitantes tendria que pertenecer a uno solo. Si se metiera `propiedad_id` en
`solicitudes`, cada solicitud podria requerir un solo inmueble.

Las dos simplificaciones rompen algo. **La relacion es de varios a varios y por
eso tiene tabla propia**: `solicitud_propiedad`.

Y no es un pivote anonimo. Ademas de los dos ids guarda:

| Columna | Para que |
|---|---|
| `usuario_id` | Quien propuso esa propiedad para esa solicitud |
| `propuesta_en` | Cuando |
| `estado` | `pendiente`, `visitada`, `descartada`, `elegida` |
| `motivo_descarte` | Por que no |

Sin eso no se puede responder "el cliente no vio este depto porque se lo
filtramos", que es una pregunta que aparece en cualquier reclamo.

---

## 8. `operaciones` — reserva y arriendo en una tabla

Comparten casi todo: cliente, corredor, oferta que cierran, comision sobre el
pactado, estado de contrato. Lo que los diferencia son las fechas, y esas fechas
estan separadas por columna **y por una restriccion de la base**:

```sql
CHECK (
    (tipo = 'reserva'  AND expira_en IS NOT NULL
                     AND fecha_inicio IS NULL AND fecha_termino IS NULL)
    OR
    (tipo = 'arriendo' AND expira_en IS NULL
                     AND fecha_inicio IS NOT NULL AND fecha_termino IS NOT NULL
                     AND fecha_termino >= fecha_inicio)
)
```

Esa restriccion es la que hace que la liberacion automática tenga algo que mirar.
Una reserva sin `expira_en` no se puede guardar ni por error: es la condicion que
impide que una propiedad quede tomada para siempre.

### `expired` y `anulada` no son lo mismo

| Estado | Significado |
|---|---|
| `expirada` | No se confirmo a tiempo. Es un problema del PROCESO |
| `anulada` | Alguien la dejo sin efecto antes de vencer. Es una DECISIÓN |

El tablero las cuenta distinto, y por eso son dos valores y no uno con un
matiz.

### El retorno del arriendo

`retornado` es el retorno: el contrato termino y no se renovo. No es un evento
neutro, es una propiedad que vuelve al mercado, y por eso hace el mismo
movimiento que una reserva vencida (un cambio de estado en el historial) pero se
cuenta aparte.

---

## 9. La comision

### La regla

```
comision = valor PACTADO x porcentaje
```

**Nunca** sobre el precio de lista de la ficha.

### Por que se guarda y no se calcula al vuelo

Tres motivos, en orden de gravedad:

1. **La comision es un dato que hay que cobrar.** Va en la tabla porque una
   comision recalculada cambia de valor cuando cambia el pactado, y el corredor
   ya vio un correo con la cifra anterior.
2. **Se negocia.** Un 3% en vez de 3,5% es una decisión, y queda registrada.
3. **Dos personas tienen que ver el mismo número.** Si uno recalcula y otro lee
   la columna, se desincronizan por redondeo.

### Como se redondea

Al peso, **hacia arriba** cuando hay resto. Redondear hacia abajo sistematicamente
le cuesta plata al corredor; hacia arriba le puede costar un peso.

### El arriendo no es un porcentaje

En arriendo la comision suele pactarse como "un mes de arriendo". Eso **no** es
un porcentaje enorme del pactado: un mes sobre doce meses es 8,33%, y redondeado
por mes el número sale mal en cualquier duracion que no sea multiplo de 12.

Por eso existe `comisionDeArriendoPorMeses()`, que calcula `$pactado / 12 *
$meses` directamente, y `comision_adicional`, que suma un monto fijo sin
convertirlo en porcentaje.

---

## 10. Indices pensados para las consultas que importan

| Indice | Para que consulta |
|---|---|
| `idx_ofertas_tenant_tipo_estado` | El tablero: cuanto hay en cada estado |
| `idx_cambios_tenant_estado_ventana` | Los tramos abiertos de un estado |
| `idx_operaciones_vencimiento` | La liberacion de reservas vencidas |
| `idx_operaciones_vencimiento_arriendo` | El aviso de renovacion |
| `idx_propiedades_tenant_corredor` | El filtro por cartera |

---

## 11. Resumen de decisiones en una tabla

| Decisión | Alternativa descartada | Motivo |
|---|---|---|
| `ofertas` separadas de `propiedades` | Estado único en la propiedad | Venta y arriendo simultaneos |
| `cambios_estado` solo agregar | Estado actual con marca de tiempo | Se necesita el historial entero |
| Estados como datos | Enum de PHP | Cada inmobiliaria configura los suyos |
| `clave_sistema` | Buscar por nombre | Renombrar no puede romper las reglas |
| Union con datos propios | Pivote de dos ids | La propuesta es un hecho, no un vinculo |
| `duracion_dias` guardada | Derivada | Medida una vez, no recalculable |
| Trigger contra reescritura | Solo convencion | El olvido mas comun es de buena fe |
| Comision sobre el pactado | Sobre el precio de lista | El pactado es lo que se cobro |
| Restriccion de fechas en la base | Solo en PHP | Una reserva sin vencimiento nunca vuelve |