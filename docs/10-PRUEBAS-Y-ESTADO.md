# 10 - Pruebas y estado

Este documento separa, sin ambigüedad, **lo que se ejecutó** de **lo que no**.

---

## 1. Estado general

> # Código escrito, sin ejecutar

En el entorno donde se construyó este proyecto:

| | |
|---|---|
| **PHP** | No disponible |
| **Composer** | No disponible |
| **Docker** | No disponible |
| **PostgreSQL** | No disponible |
| **Authentik** | No disponible |
| **Node** | Disponible |
| **bash** (WSL) | Disponible |

**Ninguna prueba de este proyecto se ejecutó.** Ninguna migración se corrió.
Ninguna consulta se hizo contra una base real.

Todo lo que sigue marcado "escrito" está en el repo y es revisable, pero
**no está verificado en ejecución**.

---

## 2. Lo que SÍ se ejecutó

Una sola cosa, y conviene decirla con precisión porque es poca.

### 2.1 Sintaxis de los scripts de shell

```bash
bash -n deploy/api/docker/entrypoint.sh
```

**Resultado: exit 0.**

Es el único artefacto del proyecto que se pudo comprobar. Se verifico también, a
mano, que las palabras clave de shell esten **en ingles** y no traducidas:

| | |
|---|---|
| `while` | presente |
| `do` | presente |
| `done` | presente |

`entrypoint.sh` no usa `until`, `for` ni `case`. Un `hasta` en vez de un `until`
rompe el contenedor, y por eso la comprobacion se hizo de forma explicita y no
solo con `bash -n` (que no detecta una palabra clave traducida si el script sigue
siendo sintacticamente valido).

### 2.2 Contencion de archivos

Se comprobó, despues de cada tanda de escrituras, que todos los archivos quedaron
dentro de `apps/inmobiliaria/` y que no se escribio nada en la raiz del workspace
ni en ninguna otra carpeta de `apps/`.

### 2.3 Barrido de caracteres fuera del español

Dos pasadas con el patrón de caracteres CJK (han, hiragana, katakana, hangul) y
cirilico, sobre **todos** los archivos del proyecto.

**Resultado final: 0 coincidencias.**

La primera pasada encontro contaminaciones reales que se corrigieron:

| Archivo | Contaminacion |
|---|---|
| `deploy/api/docker/php.ini` | Una palabra en ingles incrustada |
| `api/app/Services/Auth/ProveedorOidcAuthentik.php` | "Pegarse" pegado a otra palabra |
| `api/database/seeders/InmobiliariaDeEjemploSeeder.php` | Un ideograma chino pegado a una palabra |
| `web/app/pages/tablero.vue` | Ideogramas CJK en un comentario |
| `api/app/Http/Controllers/Administracion/EstadoController.php` | Un ideograma chino en un comentario |
| `docs/08-RESPALDOS.md` | Un ideograma chino en un comentario de un ejemplo de SQL |
| `docs/07-SEGURIDAD-Y-LEY-19628.md` | Cuatro palabras en cirilico |
| `docs/05-CONFIGURACION.md` | Un fragmento en cirilico |
| `docs/02-MODELO-DE-DATOS.md` | Un ideograma chino pegado a "busqueda" |

La segunda pasada **si** encontro una: dos ideogramas chinos que reemplazaron la
silaba "bus" de "busqueda" en `docs/02`, en una linea que la primera pasada
habia dejado pasar. Se corrigio.

Nota sobre el metodo: los ideogramas se describen aquí **sin reproducirlos**, para
que este documento no introduzca los caracteres que el resto del proyecto no debe
tener. Un archivo que documenta la contaminacion sin copiarla es un archivo que
pasa el barrido.

La segunda pasada no encontro ninguna mas. **Nótese que el barrido de CJK y
cirílico NO detecta dos palabras pegadas sin espacio**, del tipo una palabra
terminada en minúscula pegada a otra que empieza en mayúscula. Esas se
encontraron revisando a mano el texto y se corrigieron una por una.

---

## 3. Lo que NO se ejecutó

### 3.1 Migraciones

**Ninguna de las 13 migraciones se aplicó.**

Eso significa que **no se sabe** si el SQL es válido. En particular, sin
verificación:

| Riesgo | Dónde |
|---|---|
| Sintaxis de `CREATE CONSTRAINT` (el nombre es una extensión de la sintaxis estandar) | Las 13 migraciones |
| Indice único parcial `WHERE hasta IS NULL` | `cambios_estado` |
| Disparador `trg_cambios_estado_no_reescribir` con `BEFORE UPDATE` | `cambios_estado` |
| La función `public.app_tenant_id()` con `RAISE EXCEPTION ... USING ERRCODE` | La migración de RLS |
| Que `jsonb`... **nada**: este proyecto no usa JSONB | — |

Reformulado sin rodeos: este proyecto **no usa columnas JSONB**, así que ese
riesgo no aplica. Los riesgos reales son los de arriba.

**La primera accion de quien levante esto es aplicar las migraciones.** Si alguna
no aplica, el error va a estar ahi y no en ningun otro lado.

### 3.2 Pruebas automaticas

**Ninguna de las pruebas se ejecutó.** Todas necesitan PostgreSQL.

| Suite | Pruebas | Que cubre |
|---|---|---|
| `Aislamiento` | 6 | Aislamiento entre inmobiliarias y contexto entre iteraciones |
| `Permisos` | 7 | Cartera del corredor |
| `Operaciones` | 5 | Reserva vencida, retornos |
| `Estados` | 12 | Historial, permanencia, inreformabilidad |
| `Unit` | 9 | Comisiones |

**Advertencia importante**: en SQLite estas pruebas **pasarían sin comprobar
nada**, porque no hay Row Level Security. `phpunit.xml` fija `pgsql`
expresamente por eso. Si alguien corre las pruebas sin base PostgreSQL y ve un
"todo verde", ese verde no significa nada.

### 3.3 Las tres reglas del negocio

Las mas importantes, y ninguna probada en ejecución:

| Regla | Prueba | Estado |
|---|---|---|
| La reserva vencida se libera sola | `ReservaVencidaTest` | **Escrita, no ejecutada** |
| Un corredor no ve la cartera de otro | `CarteraDeCorredorTest` | **Escrita, no ejecutada** |
| El aislamiento entre inmobiliarias | `AislamientoEntreInmobiliariasTest` | **Escrita, no ejecutada** |

### 3.4 La interfaz

- **No se ejecuto `npm install`**: no hay `node_modules` ni `package-lock.json`.
- **No se compilo** (`npm run build`).
- **No se arranco** el servidor de Nuxt.
- **No se verifico** el render en el servidor, ni el reenvio del token CSRF, ni
  el comportamiento de la cookie.

La UI esta escrita, es revisable y hay que esperarse que tenga errores. Un
componente de Nuxt con un tipo mal declarado no falla al escribir el archivo,
falla al compilar.

### 3.5 Docker

- **No se construyo ninguna imagen.**
- **No se verifico** que el `entrypoint.sh` funcione: se verifico su **sintaxis**,
  no su comportamiento.
- **No se levanto** ninguna composicion.

### 3.6 La verificación criptografica del `id_token`

**No esta implementada**, y es el pendiente mas grave del proyecto.

`VerificadorIdToken::firmarConJwks()` lanza una excepcion en vez de verificar:

```php
throw new RuntimeException(
    'La verificacion criptografica del id_token debe implementarse con la biblioteca '
    . 'JWT antes de usar esto en produccion. Ver docs/10: ESTADO NO VERIFICADO.'
);
```

**Por que lanza en vez de fingir**: un metodo que devolviera claims sin comprobar
la firma seria el peor resultado posible, porque pareceria funcionando y el fallo
seria invisible. Un ingreso que falla es un ingreso fallido; un ingreso que pasa
con un token falsificado es una cuenta que cualquiera puede suplantar.

Sin esto **no hay ingreso seguro**. Es requisito bloqueante antes de producción.

Los pasos 1 (canje del código) y 3 (`iss`, `aud`, `exp`, `nonce`) estan escritos
pero tampoco se ejecutaron.

### 3.7 El comando de verificación

`inmobiliaria:verificar-aislamiento` esta escrito, con sus cinco comprobaciones.
**No se ejecutó.** Y su Fifth punto, comprobar que `public.app_tenant_id()` falle
cerrado, solo tiene sentido si las migraciones se aplicaron.

---

## 4. Lo que hay que hacer primero

En este orden. Nada de esto se puede hacer en el entorno donde se escribió el
código.

### 4.1 Antes de tocar datos de clientes

1. **Aplicar las migraciones.** Corregir lo que falle.
2. **Correr las cinco suites** contra PostgreSQL.
3. **Restaurar un respaldo y comprobar** que las políticas vienen con el.
4. **Implementar la verificación criptografica** del `id_token`. Sin esto no hay
   ingreso seguro.
5. **Compilar la interfaz** (`npm ci && npm run build`).
6. **Configurar Authentik** y entrar de verdad.

### 4.2 Antes de producción

7. Revision de seguridad externa.
8. Implementar la bitacora de accesos a fichas de cliente.
9. Decidir la política de retencion y las rutas de exportación y eliminación de
   datos personales. Ver [07](07-SEGURIDAD-Y-LEY-19628.md), seccion 1.5.
10. Probar el planificador en un entorno con tiempo real, o simular el paso del
    tiempo.

### 4.3 Lo primero que fallara

Por experiencia con este tipo de código, en este orden:

1. **La sintaxis de `CREATE CONSTRAINT`.** Es una extension de la sintaxis
   estandar de PostgreSQL. Si el servidor no la acepta, hay que pasarla a
   `ALTER TABLE ... ADD CONSTRAINT`.
2. **El disparador de `cambios_estado`.** Su firma `BEFORE UPDATE ... FOR EACH
   ROW EXECUTE FUNCTION` es correcta en PostgreSQL moderno, pero no se comprobo.
3. **Los tipos de las columnas de `ofertas`** contra los `casts` del modelo. Un
   `integer` con valor `null` y un `cast` que no lo contempla throws.
4. **El `whereNull('hasta')` combinado con `orderByDesc('desde')`** en
   `RegistradorDeCambioEstado`: es correcto, pero depende del indice único
   parcial y conviene confirmar que el planificador lo usa.

Ninguno de estos se ha descartado: son **riesgos abiertos**, no problemas
resueltos.

---

## 5. Inventario de lo escrito

| | |
|---|---|
| Migraciones | 13 |
| Modelos | 11 |
| Enums | 4 |
| Services | 7 |
| Policies | 8 |
| Controllers | 12 |
| FormRequests | 3 |
| Resources | 3 |
| Commands | 4 |
| Auth | 3 |
| Pruebas | 5 archivos, ~39 pruebas |
| Documentos | 10 |
| Config | 6 |

---

## 6. Como va esto al resto de Hefesto

La aplicación de clinicas de Hefesto (`apps/clinica-salud`) tiene las mismas
limitaciones y esta en el mismo estado: pruebas escritas y no ejecutadas. El
patron de aislamiento entre tenants es el mismo en todas las verticales, y esa
es justamente una de las razones para reutilizarlo: un patron que se repite es un
patron que se puede revisar una vez.

Ver el detalle en `apps/portal-autoservicio/docs/03-MULTITENANCY.md`.

---

## 7. Resumen honesto

| Pregunta | Respuesta |
|---|---|
| ¿El código compila? | **No se comprobó.** No hay PHP. |
| ¿Las migraciones aplican? | **No se comprobó.** No hay PostgreSQL. |
| ¿Las pruebas pasan? | **No se ejecutaron.** No hay base de datos. |
| ¿La interfaz funciona? | **No se comprobó.** No se instalaron las dependencias. |
| ¿El ingreso es seguro? | **NO.** Falta la verificación criptografica del `id_token`. |
| ¿El aislamiento esta puesto? | **No se comprobó en ejecución**, pero el SQL esta escrito siguiendo el patron ya usado en el portal de autoservicio. |
| ¿Los scripts de shell son validos? | **Si.** `bash -n` dio exit 0. |

**La respuesta honesta a "¿esto funciona?" es: no lo sabemos todavia.** Lo que si
se puede decir es que el diseño esta completo y razonado, que los riesgos mas
grandes estan identificados y escritos, y que las pruebas que los fijan estan
escritas esperando una base de datos.

---

## Los dos roles de PostgreSQL: escritos, no ejecutados

La separación entre el rol de arranque (`inmobiliaria_admin`) y el rol de la
aplicación (`inmobiliaria`) está escrita en el `compose`, en
`deploy/postgres/inicializar-rol.sh` y en
[03-MULTITENANCY-Y-ROLES.md](03-MULTITENANCY-Y-ROLES.md). **No se ha ejecutado
ninguna vez.**

En el equipo donde se escribió este cambio no había PostgreSQL ni Docker. Queda
pendiente:

1. Levantar el `compose` con el volumen de PostgreSQL vacío y confirmar que el
   rol `inmobiliaria` se crea sin error.
2. Confirmar con `SELECT rolname, rolsuper, rolbypassrls FROM pg_roles` que el
   rol de la conexión no es superusuario ni salta la RLS.
3. Correr `php artisan inmobiliaria:verificar-aislamiento` y confirmar que sale
   con código 0.
4. Confirmar que el rol no tiene permiso de `DELETE` sobre `cambios_estado`, que
   es el riesgo que quedó abierto en el documento 03.
5. Probar el escenario que motiva todo el cambio: una inmobiliaria intentando
   leer propiedades o contratos de otra.

Lo único verificado es estático: `bash -n` pasa sobre el script y el `compose`
es YAML válido, ambas cosas comprobadas por `tools/validar_apps.py`. El
razonamiento se apoya en la documentación oficial de PostgreSQL 18, no en una
ejecución.