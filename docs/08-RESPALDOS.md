# 08 - Respaldos

Un respaldo que no se ha restaurado nunca **no es un respaldo**. Es una suposicion.

---

## 1. Que hay que respaldar

| | Que es | Como |
|---|---|---|
| La base | Todo el negocio | `pg_dump` |
| Las fotos | Los archivos de las propiedades | `rsync` o `tar` sobre el volumen |
| Los secretos | `api/.env`, claves OIDC | Gestor de secretos, **no** un respaldo de la base |

**La base y las fotos son dos respaldos separados**, porque se pueden perder por
motivos distintos. Una base completa sin las fotos es una ficha sin imagen; unos
archivos sin la base son archivos que no pertenecen a nada.

---

## 2. La base

### 2.1 Respaldar

```bash
docker compose -f deploy/docker-compose.prod.yml exec -T postgres \
  pg_dump --format=custom --no-owner --no-acl \
  --file=/tmp/inmobiliaria.dump \
  -U "$DB_USERNAME" -d "$DB_DATABASE"

docker compose -f deploy/docker-compose.prod.yml cp \
  postgres:/tmp/inmobiliaria.dump ./respaldos/inmobiliaria-$(date +%F).dump
```

| Opcion | Por que |
|---|---|
| `--format=custom` | Comprimido y restaurable con `pg_restore`. Un SQL plano es mas grande y no permite elegir objetos |
| `--no-owner --no-acl` | Para que el respaldo se pueda restaurar en otra maquina, con otro usuario |
| `PURETEXT` no | Si se necesita texto plano (por ejemplo, para buscar adentro), `--format=plain`. Pesa bastante mas |

### 2.2 Restaurar

```bash
# En una base NUEVA. No sobre la de produccion: el respaldo puede estar incompleto.
docker compose -f deploy/docker-compose.prod.yml exec -T postgres \
  createdb -U "$DB_USERNAME" inmobiliaria_restaurada

docker compose -f deploy/docker-compose.prod.yml exec -T postgres \
  pg_restore --no-owner --no-acl \
  --dbname=inmobiliaria_restaurada \
  -U "$DB_USERNAME" \
  < ./respaldos/inmobiliaria-2026-10-17.dump
```

Y despues, **siempre**:

```bash
# 1. Las politicas vienen en el respaldo, pero conviene comprobarlo:
php artisan migrate:status
php artisan inmobiliaria:verificar-aislamiento
```

El último comando es el que importa: si el respaldo se tomo de una instalación
donde la RLS no estaba puesta, restaurado tal cual tampoco la va a tener, y eso
no se ve hasta que dos inmobiliarias compartan base.

### 2.3 La prueba de restauración

**Restaurar en un servidor alterno, una vez al mes**, y correr:

```sql
-- Las once tablas tienen que tener politica.
SELECT tablename, policyname FROM pg_policies WHERE schemaname = 'public';

-- Y los datos de una inmobiliaria.
SELECT tipo, estado_contrato, count(*) FROM operaciones GROUP BY 1, 2;
```

```bash
php artisan inmobiliaria:verificar-aislamiento
```

Si el respaldo no se ha restaurado jamas, no se sabe si se puede restaurar. Es la
razon por la que este punto existe en el documento y no solo en la costumbre.

---

## 3. Las fotos

```bash
docker run --rm \
  -v inmobiliaria_fotos:/origen:ro \
  -v "$PWD/respaldos":/destino \
  alpine:3.21 \
  tar czf "/destino/fotos-$(date +%F).tar.gz" -C /origen .
```

Restaurar:

```bash
docker run --rm \
  -v inmobiliaria_fotos:/destino \
  -v "$PWD/respaldos":/origen:ro \
  alpine:3.21 \
  tar xzf "/origen/fotos-2026-10-17.tar.gz" -C /destino
```

Las rutas en la base son **relativas** al volumen de fotos, así que restaurar los
archivos en el volumen correcto los deja funcionando sin tocar la base. Esa es la
razon de que las rutas no sean absolutas.

---

## 4. Cuanto seguido

| | Que | Cada cuanto |
|---|---|---|
| Base | `pg_dump` | **Diario** |
| Fotos | `tar` | **Diario**, con la base |
| Verificación de restauración | En un servidor alterno | **Mensual** |

Un respaldo diario es el minimo. Con una inmobiliaria de tres personas, el costo
es bajo y el costo de perder una semana de carga de propiedades es alto.

---

## 5. Donde guardarlos

Los respaldos **no pueden vivir en la misma maquina** que el sistema. Si se
pierde el disco, se pierden los dos.

| Opcion | Para que instalación |
|---|---|
| Copia local cifrada + una en otro sitio | Una sola inmobiliaria |
| S3 o equivalente, con versionado | Varias inmobiliarias |

Con retencion: **14 diarios, 6 semanales, 12 mensuales**. El mes es importante
porque una reclamation de un cliente puede aparecer un ano despues, y para eso hay
que poder mostrar que se guardaba y por que.

---

## 6. Consideraciones de la Ley 19.628

El respaldo **también es una copia de datos personales**, y tiene las mismas
obligaciones:

- **Cifrado en transito y en reposo.** Un `.dump` de PostgreSQL en claro es una
  copia completa de clientes, telefonos, correos y operaciones.
- **El mismo aislamiento.** No es valido guardar un respaldo accesible desde una
  red mas amplia que la de producción.
- **La retencion aplica también.** Un respaldo de hace dos años con datos de
  clientes que ya no se necesitan es un problema, no una prudencia.
- **El borrado tiene que llegar al respaldo.** Si un cliente pide la
  eliminación y el `.dump` de hace tres meses todavia lo tiene, no se elimino
  nada.

> **PENDIENTE**: la aplicación **no implementa** eliminación ni exportación de
> datos de clientes, así que no hay una ruta que "llegue al respaldo". Es la
> razon por la que la política de retencion de la seccion 1.5 de
> [07](07-SEGURIDAD-Y-LEY-19628.md) no es solo un pendiente de negocio: es un
> pendiente de cumplimiento.

---

## 7. Restaurar un respaldo completo

El orden importa. La base primero, las fotos despues:

```bash
# 1. Base
docker compose -f deploy/docker-compose.prod.yml stop api web
# ... restaurar con pg_restore ...
# 2. Fotos
# ... restaurar con tar ...
# 3. Comprobar
php artisan migrate:status
php artisan inmobiliaria:verificar-aislamiento
docker compose -f deploy/docker-compose.prod.yml start api web
```

Y despues de levantar, abrir el tablero y confirmar que:

- Las propiedades aparecen con sus fotos.
- Los historiales de estados estan completos.
- El isolation sigue en pie.

Un respaldo restaurado que no se revisa con ojos humanos es un respaldo que puede
estar tecnicamente completo y ser inservible.