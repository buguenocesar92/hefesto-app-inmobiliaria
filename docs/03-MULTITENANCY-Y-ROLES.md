# 03 - Aislamiento entre inmobiliarias y roles

Este es el documento mas importante del proyecto.

El riesgo número uno no es que falle una pantalla: es que el dueño de la
**Inmobiliaria Sur** vea las reservas, los clientes y el historial de estados de
la **Inmobiliaria Norte**. Todo lo demas es menos importante que eso.

---

## 1. Por que no puede depender solo del código

Si una consulta se ejecuta sin `tenant_id`, o con el de otro, el sistema **no da
ningun aviso**: devuelve filas que no deberia y la interfaz se ve perfecta.

Por eso el aislamiento se implementa en dos capas:

| Capa | Que hace | Que detecta |
|---|---|---|
| Aplicación (Policies y middleware) | Decide y filtra | Errores de lógica y consultas mal escritas |
| Base de datos (Row Level Security) | Impide que PostgreSQL devuelva filas ajenas | Todo lo que se le escape a la capa de arriba |

La base es la última linea. Si la aplicación tiene un error, la base sigue sin
entregar datos de otra inmobiliaria.

---

## 2. Patron elegido: columna `tenant_id` + RLS

Reutilizado tal cual del patron documentado en
`apps/portal-autoservicio/docs/03-MULTITENANCY.md`, sin variantes.

Todas las tablas de negocio llevan `tenant_id`, y PostgreSQL aplica una política
que exige que `tenant_id` sea igual al de la peticion.

### Por que NO un esquema por inmobiliaria

La alternativa clasica, y las razones concretas para descartarla:

1. **Las migraciones se multiplican.** Con 30 inmobiliarias son 30 ejecuciones
   por migración. Un cambio de esquema se vuelve un proceso largo y fragil.
2. **El pool de conexiones se vuelve el cuello de botella.** Se dimensiona por
   el número de combinaciones (esquema, rol) que se usan de verdad. Con columna
   única, todas las peticiones comparten el mismo pool.
3. **Laravel obliga a duplicar la lógica de esquema.** Cada consulta, migración,
   prueba y cola necesita acordarse del esquema correcto. Es la clase de
   acoplamiento que produce fugas.
4. **El aislamiento queda en manos de la aplicación.** El `search_path` es un
   parámetro de sesión: si queda mal puesto, la consulta va al esquema
   equivocado y **no falla**. Devuelve datos de la inmobiliaria equivocada sin
   error visible.
5. **El costo, y por que se acepto**: todas las consultas deben tener contexto
   de tenant. Ese costo esta forzado por el diseno (seccion 4).

---

## 3. El mecanismo, en detalle

Definido en `api/database/migrations/2026_02_10_000013_activar_row_level_security.php`.

### 3.1 La función de contexto

```sql
CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid
LANGUAGE plpgsql STABLE AS $$
DECLARE
    valor text;
BEGIN
    valor := current_setting('app.tenant_id', true);

    IF valor IS NULL OR valor = '' THEN
        RAISE EXCEPTION
            'Falta el contexto de inmobiliaria (app.tenant_id). '
            'Usa App\Services\Tenancy\ContextoTenant::ejecutarAislado().'
            USING ERRCODE = 'insufficient_privilege';
    END IF;

    RETURN valor::uuid;
END;
$$
```

La función **lanza excepcion** si no hay tenant. Podria haber devuelto `NULL` y
filtrado todo en silencio, pero se eligio que falle de forma ruidosa:

- Si devuelve `NULL`, la consulta devuelve cero filas sin aviso. Alguien lo
  "arregla" devolviendo `NULL` cuando hace falta (un reporte, un comando) y la
  puerta queda abierta sin que nada lo delate.
- Si lanza excepcion, el error aparece en el log **en el momento**, y se sabe
  exactamente que operación se escapo del middleware.

Es el mismo criterio que un candado: importa que falle cerrado.

### 3.2 La política

Para cada tabla de negocio:

```sql
ALTER TABLE public.cambios_estado ENABLE ROW LEVEL SECURITY;
ALTER TABLE public.cambios_estado FORCE ROW LEVEL SECURITY;

CREATE POLICY aislamiento_tenant ON public.cambios_estado
    USING (tenant_id = public.app_tenant_id())
    WITH CHECK (tenant_id = public.app_tenant_id());
```

Tres detalles que importan:

- **`USING`** filtra lo que se **lee**.
- **`WITH CHECK`** impide **escribir** una fila con un `tenant_id` distinto. Sin
  esto, un `INSERT` con el tenant equivocado dejaria una fila invisible que
  nadie podria ver ni borrar.
- **`FORCE ROW LEVEL SECURITY`** hace que la política aplique **también al dueno
  de la tabla**. Sin FORCE, el dueno la saltaria. En desarrollo el rol de la
  aplicación suele ser el mismo que creo las tablas: sin FORCE, el aislamiento
  seria decorativo justo en el entorno donde se prueba.

No se usa ninguna función `SECURITY DEFINER` a propósito: una función definida
por el dueno saltaria sus propias políticas.

### 3.3 Como viaja el tenant

```php
DB::select('SELECT set_config(?, ?, true)', ['app.tenant_id', $inmobiliariaId]);
```

El `true` final es lo mas importante: **alcance de transacción**. Al confirmar o
revertir, PostgreSQL descarta el valor solo.

Con un `SET` normal (alcance de sesión) el valor sobreviviria a la peticion. Y el
**worker de colas reutiliza la misma conexión** para trabajos de inmobiliarias
distintas: un `SET` a sesión significa que un trabajo de la Sur podria leer los
datos de la Norte.

Por eso `ContextoTenant::establecer()` **lanza excepcion si no esta dentro de una
transacción**.

### 3.4 Esto no es teorico en ESTA aplicación

El trabajo que libera reservas vencidas recorre **todas** las inmobiliarias en la
misma conexión. Es exactamente el caso para el que el alcance de transacción se
eligio:

```php
foreach ($inmobiliarias as $inmobiliaria) {
    // UNA transaccion por inmobiliaria.
    $contexto->ejecutarAislado(
        (string) $inmobiliaria->id,
        fn () => $liberador->liberarPara((string) $inmobiliaria->id)
    );
}
```

La prueba `test_el_contexto_no_sobrevive_entre_iteraciones` de
`AislamientoEntreInmobiliariasTest` comprueba, sin necesidad de un worker, que dos
`ejecutarAislado` seguidos en la misma conexión no se ven entre si, y que al
terminar no queda contexto.

---

## 4. El punto ciego: `inmobiliarias`

No lleva RLS, a propósito. Se consulta durante el login, cuando todavia no hay
sesión y por lo tanto no hay `app.tenant_id`.

Es una excepcion consciente, con dos compensaciones:

**Compensacion 1: hoy no está activa.** El código lee la **tabla**
`inmobiliarias`, no una vista. `App\Services\Auth\ProveedorOidcAuthentik` resuelve
la inmobiliaria por `correlacion_identidad` con
`Inmobiliaria::query()->where(...)->value('id')`, que trae **solo el identificador**,
y luego con `->first()`, que trae la fila entera. Antes del login no hay
`app.tenant_id`, y por eso `inmobiliarias` es la excepción controlada del patrón.

La tabla `inmobiliarias` sí guarda datos de contacto (`razon_social`, `rut`,
`direccion`, `telefono`, `correo`), pero **el código no los devuelve al navegador**
antes de autenticarse: la resolución ocurre en el servidor y de la fila solo se usa
el identificador para seguir con el ingreso. Lo que se expone hacia el exterior son
columnas de identificación (`slug`, `nombre`) y nada de clientes, propiedades ni
expedientes.

La vista `vw_inmobiliarias_publicas` **existe** (migración `2026_02_10_000013`, que
expone `slug`, `nombre` y `activo` de las activas) y **queda sin uso**: el código de
ejecución nunca la consulta. Solo aparece en la prueba
`test_las_inmobiliarias_no_cuentan_como_negocio_protegido`, que comprueba que
exponga exactamente esas tres columnas. La vista no se eliminó: se conserva como
defensa en profundidad y como respaldo para cuando se decida activar la protección.

**Pendiente, no comprobado:** activar la vista exige revisar qué espera la interfaz
del ingreso y si la fila completa aporta algo que la vista no traiga. No se ha
podido comprobar contra una base de datos porque no había una disponible, así que
la decisión sigue abierta. Una versión anterior de este documento afirmaba que la
aplicación leía la vista; era falso y se corrige aquí.

**Compensacion 2: el rol de la aplicación no es superusuario.** Quien tenga
acceso SQL directo puede leer `inmobiliarias` entera. `inmobiliaria:verificar-aislamiento`
comprueba que el rol no sea superusuario ni tenga `BYPASSRLS`.

**Riesgo residual, dicho con todas las letras:** un `slug` se puede enumerar antes
de autenticarse, porque el slug es público (aparece en la URL de entrada). Un
atacante puede descubrir que la Inmobiliaria Sur usa el sistema. No puede ver mas
que su nombre. Si incluso eso se considera inaceptable, la alternativa es resolver
siempre por equipo de Authentik y dejar el slug en blanco hasta el login; queda
anotado como decisión pendiente, no como algo resuelto.

---

## 5. `cambios_estado` también esta aislada, y especialmente importa

Podria parecer que el historial es "información interna" y que no necesita la
misma protección. Si un corredor de la Sur pudiera leer los cambios de estado de
la Norte sabria:

- El **valor pactado** de una operación ajena, si aparece en el motivo.
- Como trabaja el otro: cuantos dias demora en reservar, cuantas reservas se le
  caen.

Eso es información comercial de la competencia. Va con RLS como cualquier otra, y
tiene su propia prueba en la suite de aislamiento.

---

## 6. Doble filtro dentro del tenant

La RLS garantiza que nada sale de la inmobiliaria. Dentro, hay un segundo filtro:
la **cartera**.

Un corredor ve su cartera. El administrador ve la casa entera.

| Recurso | Corredor | Administrador |
|---|---|---|
| Propiedades | las que tiene asignadas | todas |
| Ofertas | las que lleva | todas |
| Historial de estados | el de sus ofertas | todos |
| Clientes y solicitudes | los de su cartera | todos |
| Visitas | las que realizo | todas |
| Operaciones | las suyas, **sin el dinero** | todas, con el dinero |
| Estados (leer) | todos | todos |
| Estados (modificar) | nunca | siempre |
| Usuarios | nunca | siempre |

### Dos decisiones que conviene que sean explicitas

**Una propiedad sin corredor asignado NO la ve el corredor, solo el
administrador.** Podria parecer justo al reves ("nadie la tiene, entonces es de
todos"), pero ese recurso es la cartera sin dueno del administrador, y mostrarlo
a todos es el camino mas corto a una visita duplicada entre dos corredores.

**El dinero de la operación es solo del administrador.** `valor_pactado` y
`comision_monto` vuelven en `null` para el corredor, no con un 403. La operación
se ve igual: el corredor necesita saber si su reserva esta confirmada. Un 403
sobre un recurso que ya se esta mostrando obliga al cliente a tratar un caso
especial, y los casos especiales son donde aparecen las fugas.

### 404, no 403

Cuando una Policy rechaza, la respuesta es **404**. Un 403 confirmaria que el
recurso existe, que es información sobre la cartera de un colega.

El filtro por tenant ya impide que el recurso exista a ojos de la otra
inmobiliaria, así que 404 es ademas la respuesta correcta en el caso habitual.

---

## 7. Donde vive cada filtro

| Criterio | Donde se aplica | Por que |
|---|---|---|
| `tenant_id` | **La base** (RLS) | No puede olvidarse |
| Cartera, en el listado | **La consulta** | Filtrar despues traeria el inventario entero a memoria |
| Cartera, en el recurso individual | **La Policy** | Se evalua por recurso, y autoriza antes de buscar |
| Rol de administrador | **Middleware `admin`** | Corta antes del controlador |
| Alcance de dinero | **La Policy**, via un flag del Resource | Para que el recurso salga bien formado |

El orden importa: **autorizar primero, buscar despues**. El filtro de cartera se
puede meter en la consulta (bien) o dejar que la consulta traiga la fila y la
policy la rechace (mal, porque entonces la fila ya estuvo en memoria).

---

## 8. Comprobacion en el despliegue

`php artisan inmobiliaria:verificar-aislamiento` corre en el arranque y **frena el
contenedor** si algo falla. Comprueba:

1. Que el rol de la base no sea superusuario.
2. Que no tenga `BYPASSRLS`.
3. Que las once tablas tengan la política `aislamiento_tenant`.
4. Que las once tengan `FORCE ROW LEVEL SECURITY`.
5. Que `public.app_tenant_id()` **falle cerrado** sin contexto.

El punto 5 es el importante: es donde un cambio de alguien puede romper el
aislamiento sin romper nada visible.

---

## 9. Lo que NO se hizo, y sigue siendo un riesgo

- **No hay revision de seguridad externa.**
- **No hay pruebas de penetracion.**
- **Las pruebas de aislamiento estan escritas pero NO se ejecutaron**: en el
  entorno donde se escribieron no habia PostgreSQL. Ver
  [10](10-PRUEBAS-Y-ESTADO.md).
- **La política protege el dato, no el archivo.** Las fotos se sirven desde la
  API despues de autorizar, y `FotoPropiedad::rutaEsSegura()` impide que una
  ruta con `..` salga del volumen. Si se escribiera una ruta maliciosa en la base,
  la comprobacion impide que salga.
- **`cambios_estado` bloquea la reescritura del `desde`, pero no el borrado de un
  tramo cerrado.** Un `DELETE` manual desde SQL borraria historial. El rol de la
  aplicación no tiene por que tener permiso de borrado sobre esa tabla, y eso hay
  que revisarlo al crear el rol. Ver [07](07-SEGURIDAD-Y-LEY-19628.md).

---

## 10. Los dos roles de PostgreSQL

La base de esta aplicación corre con **dos** roles, no con uno. La separación es
deliberada: es lo que hace que el aislamiento entre inmobiliarias funcione en
vez de quedar como decoración.

### Qué es cada rol

| Rol | Nombre | De dónde sale | Para qué sirve | ¿Lo usa la aplicación? |
|---|---|---|---|---|
| **Arranque** | `inmobiliaria_admin` | `POSTGRES_USER` | Crear la base `inmobiliaria` y las tareas administrativas del despliegue | **Nunca** |
| **Aplicación** | `inmobiliaria` | `DB_USERNAME`, creado por `deploy/postgres/inicializar-rol.sh` | Todo el tráfico de la API | **Sí, este y solo este** |

El rol de aplicación **no** lleva sufijo `_app`: se llama `inmobiliaria`, igual
que la base.

El rol de arranque no es una convención del estudio: la imagen oficial de
PostgreSQL ejecuta `initdb --username="$POSTGRES_USER"`, así que ese rol nace
**superusuario**.

### Por qué con un solo rol no servía

La documentación de PostgreSQL 18 lo dice de forma textual:

> *«Superusers and roles with the `BYPASSRLS` attribute always bypass the row
> security system when accessing a table. Table owners normally bypass row
> security as well, though a table owner can choose to be subject to row
> security with `ALTER TABLE ... FORCE ROW LEVEL SECURITY`.*

La segunda frase es la clave. `FORCE ROW LEVEL SECURITY` sujeta al **dueño** de
la tabla, pero **no al superusuario**. Mientras la aplicación conectara con el
rol de `POSTGRES_USER`, ninguna política de este documento se iba a aplicar: una
inmobiliaria habría visto las propiedades, los arriendos y los contratos de las
demás, sin error y sin aviso. El aislamiento entre inmobiliarias era decorativo.

### Los atributos van todos escritos

`deploy/postgres/inicializar-rol.sh`, montado en
`/docker-entrypoint-initdb.d/`, crea el rol con `NOSUPERUSER`, `NOCREATEDB`,
`NOCREATEROLE`, `NOINHERIT` y `NOBYPASSRLS` **explícitos todos**, aunque
`NOBYPASSRLS` ya sea el valor por omisión.

Se escriben igual a propósito: un rol verificado no debería depender de un valor
por omisión que una versión nueva puede cambiar. El script también falla
ruidosamente si falta `POSTGRES_DB`, `APP_DB_ROLE` o `APP_DB_PASSWORD`.

### Por qué el rol de aplicación es dueño de la base

Justo después de crearlo, el script corre:

```sql
ALTER DATABASE inmobiliaria OWNER TO inmobiliaria;
```

Las migraciones de Laravel se ejecutan con el rol de la aplicación y necesitan
**crear tablas**. La única forma de darles ese permiso sin convertirlos en
superusuario es que ese rol sea el dueño de la base. Desde PostgreSQL 15 el
esquema `public` pertenece al dueño de la base, así que con esto el rol ya
puede crear tablas ahí.

Y este es el punto exacto del diseño: al ser el dueño, **las tablas quedan a su
nombre**, y por eso el `FORCE ROW LEVEL SECURITY` que usan las tablas sí lo
sujeto. Ser dueño no es saltarse la política; es lo que hace que la política lo
alcance. Un superusuario la saltearía siempre.

Esto responde también a una pregunta que quedó abierta en la sección anterior:
el rol ya no se crea a mano, lo crea el script, y por diseño no puede borrar
filas de `cambios_estado` porque sus atributos están escritos. Lo que sigue sin
comprobarse es si el permiso de `DELETE` sobre esa tabla queda efectivamente
restringido en el despliegue real.

### Cómo se comprueba

```
php artisan inmobiliaria:verificar-aislamiento
```

Una particularidad de este comando: no consulta el rol de la conexión actual,
sino que lee el nombre de usuario de la configuración
(`database.connections.pgsql.username`) y busca **ese** rol en `pg_roles`. Es
justo el rol de aplicación, así que la comprobación es válida, y falla con
código 1 si no existe, si es superusuario o si tiene `BYPASSRLS`.

**Este comando no se ha ejecutado.** En el equipo donde se escribió este cambio
no había PostgreSQL ni Docker. El diseño es correcto y está sostenido en la
documentación oficial, pero nadie lo ha corrido. Ver
[10-PRUEBAS-Y-ESTADO.md](10-PRUEBAS-Y-ESTADO.md).