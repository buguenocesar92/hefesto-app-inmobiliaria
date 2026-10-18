# 07 - Seguridad y datos personales (Ley 19.628)

Este documento tiene dos partes: **seguridad** (como se protege la aplicación) y
**datos personales** (como se tratan los datos de los clientes). La segunda parte
no es un adorno: en una inmobiliaria la mayor parte de la base es de personas.

---

## 1. Datos personales

### 1.1 Que datos hay, y de quien

| Dato | De quien | Donde vive |
|---|---|---|
| Nombre, telefono, correo | **Cliente** | `clientes` |
| Notas del cliente | **Cliente** | `clientes` |
| Que busca, con que presupuesto | **Cliente** | `solicitudes` |
| A que propiedades se le propuso | **Cliente** | `solicitud_propiedad` |
| Cuando lo visitaron y que dijo | **Cliente** | `visitas` |
| Nombre, telefono, correo | **Propietario** | `propiedades` |
| Dias que estuvo en cada estado | **Cliente** (dato derivado) | `cambios_estado` |
| Valor pactado y comision | **Cliente** | `operaciones` |

Los cuatro primeros bloques son datos personales **sin discusión**: identifican a
una persona y describen su situacion economica y familiar. Bajo la Ley 19.628
son datos cuyo acopio debe tener finalidad declarada.

### 1.2 El principio que se aplica

> Solo se guarda lo que tiene una finalidad declarada, y solo el tiempo que esa
> finalidad lo necesita.

Consecuencias concretas en este diseño:

**a) No hay recuperacion de contraseno.** Las cuentas se administran en Authentik y
llegan por OIDC. Un formulario de "olvide mi contraseno" sería una via para crear
cuentas sin pasar por el proveedor.

**b) El consentimiento se pide al crear el cliente, y es obligatorio.**

```php
'consentimiento_contacto' => ['required', 'boolean'],
```

No es un campo opcional de la ficha: es la base para guardar el telefono y el
correo. Sin esa base, es un dato guardado sin finalidad declarada, que es
justamente lo que la Ley 19.628 prohibe.

`consentimiento_fecha` se escribe junto a `consentimiento_contacto`.

**c) No se borran clientes.** Se desactivan.

Un cliente tiene visitas y operaciones que hay que poder leer meses despues,
para un reclamo o para una referencia comercial. Borrarlo dejaria filas huerfanas
y, peor, borraria información personal sin dejar rastro de que existió. Y la
Ley 19.628 pide justamente poder demostrar el origen de los datos que se
conservan.

`ClientePolicy::delete()` devuelve `false` siempre.

**d) No se borran estados con historial.** Un estado con filas en
`cambios_estado` es información que el sistema ya le mostro a alguien.
Desvincularlo deja tramos huerfanos y el informe pasa a mentir.

### 1.3 El dato mas sensible: el valor pactado

`operaciones.valor_pactado` y `comision_monto` son las dos cifras mas
delicadas del negocio:

- Una revela que el dueño acepto 120 millones cuando la ficha estaba en 140.
- La otra es la diferencia entre lo que rindio el mes y lo que se le pago.

Por eso `OperacionPolicy::verDinero()` es **solo para administradores**. Al
corredor las cifras le llegan en `null`, no con un 403: el corredor recibe una
respuesta bien formada y la interfaz no tiene que tratar un caso especial.

El precio de **lista** si se muestra siempre: esta en la ficha y no es sensible.
Sin el, el corredor no puede comparar su operación con lo que esta en el
mercado.

### 1.4 Acceso por cartera

Un corredor **no** ve clientes de otro corredor de la misma inmobiliaria. No es
un tecnicismo de permisos: es el limite entre los portfolios de personas que
trabajan en el mismo lugar y compiten entre si.

La prueba `CarteraDeCorredorTest` lo fija. Ver
[03](03-MULTITENANCY-Y-ROLES.md).

### 1.5 Que falta decidir, y no esta resuelto

| | |
|---|---|
| **Retencion** | No hay política de retencion implementada. El `.env` tiene `CLINICA_RETENCION_*` en la vertical de clinicas; aca **no existe equivalente**. Hay que decidir cuanto se guarda un cliente sin actividad, y que se hace con sus visitas. |
| **Exportación** | No hay ruta de exportación de datos de un cliente. La Ley 19.628 contempla la portabilidad; no esta implementada. |
| **Eliminación** | No hay ruta de eliminación. Se decidio no borrar clientes, lo cual es una decisión de negocio, no el cumplimiento de una obligacion legal. Si el cliente pide la eliminación, hoy no hay camino. |
| **Bitacora de accesos** | A diferencia de la vertical de clinicas, **no hay registro de quien abrio que ficha de cliente**. Para fichas de salud eso es necesario por ley; para clientes no es obligatorio, pero es la misma práctica. Decidir si se implementa. |

**Ninguna de estas cuatro cosas esta implementada.** Se listan porque una
aplicación que guarda datos personales tiene que responderlas antes de ir a
producción, no despues.

---

## 2. Seguridad

### 2.1 Aislamiento entre inmobiliarias

Es lo mas importante, y tiene su propio documento:
[03](03-MULTITENANCY-Y-ROLES.md). Resumen:

- Row Level Security de PostgreSQL, con `WITH CHECK` y `FORCE`.
- Contexto con **alcance de transacción**, para que el worker de colas no arrastre
  el tenant de otra inmobiliaria.
- `public.app_tenant_id()` **falla cerrado**: sin contexto, lanza excepcion.
- `inmobiliaria:verificar-aislamiento` frena el despliegue si algo falta.

### 2.2 CSRF

Todas las rutas cuelgan del grupo `web` de Laravel, que valida el token CSRF en
las peticiones que modifican datos. El token viaja en la cookie `XSRF-TOKEN` y se
reenvia en la cabecera `X-XSRF-TOKEN`, que pone el BFF de Nuxt.

El sintoma de que esto esta roto son **todos los POST devolviendo 419**. Ver
`web/server/utils/api.ts`, que explica por que el reenvio no es opcional.

El servidor de Nuxt no tiene token propio: reenvia la cookie de la API. Por eso
el navegador no tiene ningun token guardado, que es lo que hace que un XSS sea
menos grave.

### 2.3 Sesión

| Medida | Valor | Motivo |
|---|---|---|
| `SESSION_EXPIRACION_MINUTOS` | 120 | Una sesión abierta mucho rato es una sesión abierta a la cartera |
| `http_only` | `true` | JavaScript no lee la cookie |
| `secure` | `true` en producción | Sin TLS, la cookie viaja en claro |
| `same_site` | `Lax` | `Strict` romperia el retorno desde Authentik |
| `SESSION_DRIVER` | `database` | La sesión no vive en el navegador |

El tiempo de vida **no esta en PHP** sino en la variable de entorno, para que sea
una decisión de instalación y no un número enterrado en un archivo de
configuración.

### 2.4 Validación

Toda entrada pasa por un `FormRequest` o por `validate()` con reglas
explicitas. Los tres casos donde hay algo mas que un "campo obligatorio":

| Regla | Donde |
|---|---|
| Un corredor no se asigna a si mismo ni a otro | `GuardarPropiedadRequest` |
| El estado de destino tiene que ser de la misma inmobiliaria | `CambiarEstadoRequest` |
| El estado de destino tiene que aplicar al tipo de operación | `CambiarEstadoRequest` |

La del estado de destino tiene una razon de seguridad concreta: sin ella, se
podria pasar el id de un estado de otra inmobiliaria y la API responderia "no
encontrado", que es un 422 y **le confirma a quien pregunta que ese id existe en
algun lado**. Se resuelve con un 404.

### 2.5 Autorización

Tres capas, y el orden importa:

1. **Middleware `admin`** corta antes del controlador. Es un permiso de rol, no
   un recurso que exista o no, así que responde 403.
2. **Policies** deciden sobre el recurso. Responden 404, porque un 403 confirmaria
   que el recurso existe.
3. **La base** no deja salir filas de otra inmobiliaria.

Y siempre **autorizar antes de buscar**. El filtro de cartera se mete en la
consulta para el listado (para no traer el inventario entero a memoria) y se
evalua con el recurso ya cargado para el detalle.

### 2.6 Autorización del dinero

`OperacionResource` lleva un flag `veDinero` que la policy define. Las cifras
sensibles viajan en `null` en lugar de omitirse.

Un recurso distinto para el administrador seria mas rigido, pero obliga al cliente
a manejar dos formas de la misma respuesta. Un `null` explicito es mas simple de
consumir y menos propenso a filtrar por un campo olvidado.

### 2.7 Rutas de archivo

Las fotos se sirven desde la API despues de autorizar, y `FotoPropiedad::
rutaEsSegura()` impide una ruta con `..`, absoluta o con segmento de retroceso.

Las rutas se guardan **relativas** al volumen, nunca absolutas. Es lo que permite
montar el volumen en otro servidor sin romper lo ya guardado.

> **Riesgo residual, dicho con todas las letras**: la comprobacion esta en el
> código, no en la base. Si alguien escribe una ruta maliciosa en la base, la
> política de la base no la va a detener. La ruta se sirve, se comprueba y, si no
> es segura, se responde 404.

### 2.8 Registro

```env
LOG_LEVEL=info
APP_DEBUG=false
```

En producción tiene que ser `info` o mas alto. Con `debug`, el registro puede
incluir el **cuerpo** de las peticiones, y el cuerpo de un POST de clientes o de
operaciones contiene datos personales.

El canal `auditoria` es aparte y mas duradero (365 dias), para que un cambio de
estado de una oferta se pueda leer seis meses despues aunque el registro general
ya se haya rotado.

### 2.9 El id_token

La verificación:

1. Canjea el `code` por tokens en el `token_endpoint`.
2. Verifica la **firma** del `id_token` contra el JWKS de Authentik.
3. Comprueba `iss`, `aud`, `exp` y `nonce`.
4. Rechaza `alg: none` antes de verificar nada.

Un id_token verificado solo por su contenido es una cadena de texto que cualquiera
puede escribir.

> **PENDIENTE Y DECLARADO**: el paso 2, la verificación criptografica de la firma,
> **no esta implementado**. `VerificadorIdToken::firmarConJwks()` lanza una
> excepcion en vez de fingir que verifica. Es una decisión consciente: un metodo
> que devolviera claims sin comprobar la firma seria el peor resultado posible,
> porque pareceria funcionando. Implementar esto es **requisito bloqueante antes
> de producción**. Ver [10](10-PRUEBAS-Y-ESTADO.md).

### 2.10 Lo que no se hizo

| | |
|---|---|
| **Revision de seguridad externa** | No hay. |
| **Pruebas de penetracion** | No hay. |
| **Limite de intentos de ingreso** | Authentik lo maneja del lado del proveedor, no aca. Correcto. |
| **Registro de accesos a fichas de cliente** | No hay. Ver 1.5. |
| **Eliminación de datos personales** | No hay. Ver 1.5. |
| **Cifrado de datos en reposo** | Depende del volumen de disco, no de la aplicación. No esta descrito en ningun lado. |

**Ninguna de estas cosas esta resuelta.** La primera y la segunda son las que
importan antes de poner datos reales de clientes en producción.