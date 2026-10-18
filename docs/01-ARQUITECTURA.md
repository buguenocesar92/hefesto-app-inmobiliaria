# 01 - Arquitectura

> PostgreSQL corre con dos roles: uno de arranque y otro de aplicación. Solo el
> de aplicación conecta con el sistema, y es el que las políticas de
> aislamiento pueden alcanzar. Ver [03](03-MULTITENANCY-Y-ROLES.md).

Hefesto Inmobiliaria es una aplicación propia de Hefesto Studio para
inmobiliarias: gestión de propiedades, ventas y arriendos.

---

## 1. Que problema resuelve, en una frase

Una inmobiliaria **no vende, ofrece**. Una propiedad no esta "vendida" en un
momento: va cambiando de estado muchas veces. Y lo que cuesta plata no es el
estado: es **la duracion de cada estado**. Una propiedad que estuvo reservada
ocho meses es una propiedad que estuvo ocho meses sin vender.

Esa cifra no existe hoy en ninguna parte. No esta en el CRM, no esta en la planilla
de Excel, no esta en la bolsa de propiedades. Por eso el producto es, antes que una
herramienta de gestión, **un reloj**: medir cuanto tiempo lleva cada propiedad en
cada estado y mostrarlo.

---

## 2. Las tres ideas que sostienen el sistema

### 2.1 La propiedad no tiene estado; la oferta si

Una propiedad **puede estar en venta y en arriendo al mismo tiempo**, y puede
pasar de una a otra varias veces.

Si el estado viviera en la tabla `propiedades`, no habria forma de decir "este
depto lleva 40 dias en venta y 200 en arriendo", que es justamente la pregunta
que el producto viene a responder.

Por eso existe la tabla `ofertas`: una entidad propia, una por tipo de operación,
con su propio estado y su propia linea de tiempo.

```
Propiedad 1458
  - Oferta VENTA     -> estado "En oferta" desde 2026-01-12   (214 dias)
  - Oferta ARRIENDO  -> estado "Arrendada" desde 2026-03-01   (156 dias)
```

### 2.2 El historial es la tabla mas importante

`cambios_estado` es **solo agregar**. Cada fila es un tramo: cuanto tiempo estuvo
la oferta en un estado.

| | |
|---|---|
| `desde` | Cuando entro al estado |
| `hasta` | Cuando salio. `NULL` significa que sigue ahi |
| `duracion_dias` | Se escribe al cerrar el tramo, una vez, y ya no se recalcula |

`hasta IS NULL` significa exactamente "el estado actual". Esa convencion hace
que la linea de tiempo se lea sin lógica: no hay que buscar el maximo, no hay que
reconstruir la secuencia.

Y hay un disparador de PostgreSQL que **impide modificar el `desde` de un tramo
ya cerrado**. Un promedio de permanencia que uno puede ajustar para que salga
mejor no es una medida.

### 2.3 Las reglas automaticas son automaticas

Dos reglas del negocio no dependen de que alguien se acuerde:

- **La reserva vencida se libera sola.** Si nadie confirma antes de la fecha, la
  propiedad vuelve al mercado. Si el sistema no lo hace, la inmobiliaria pierde
  ventas por inercia.
- **El arriendo terminado se devuelve al mercado.** Un arriendo que termina sin
  renovar es un retorno, no un evento neutro.

Las dos mueven el estado de la oferta y dejan el cambio anotado en el historial.

---

## 3. Como esta armado

```
                  navegador
                      |
                 (cookie de sesion)
                      |
    +-----------------+-----------------+
    |                                   |
  Nuxt 4 (SSR)                       API Laravel
  web/                               api/
    |                                   |
    |  BFF: reenvia cookies           |  middleware ResolverTenant
    |  y token CSRF                   |  -> fija el contexto de tenant
    |                                   |
    +-----------------------------------+
                      |
              PostgreSQL + RLS
              Redis (cache y colas)
```

### Por que un BFF y no la API expuesta al navegador

El navegador nunca habla directo con la API. Habla con el servidor de Nuxt, que
reenvia la cookie de sesión. La razon es una sola: **no hay ningun token en el
código del navegador**, entonces no hay nada que robar de un XSS ni que
expirar ni que renovar.

El precio es un servidor mas. El beneficio es que toda la política de sesión y
CSRF vive en un solo lugar y no hay dos clientes que implementarla distinto.

### Por que render en el servidor

El tablero de permanencia y la lista de clientes no deben quedar en el HTML de
una pagina pública. Una pantalla que se dibuja en el servidor no depende de que
el JavaScript haya cargado para no filtrar nada.

---

## 4. Modulos

| Modulo | Que resuelve | Tablas |
|---|---|---|
| **Propiedades** | Ficha, caracteristicas, fotos, precios de lista | `propiedades`, `fotos_propiedad` |
| **Estado y trazabilidad** | Historial de cambios con fecha y usuario | `estados`, `ofertas`, `cambios_estado` |
| **Clientes y contactos** | Solicitantes y sus requisitos | `clientes`, `solicitudes`, `solicitud_propiedad` |
| **Visitas** | Agenda y resultado | `visitas` |
| **Operaciones** | Reservas y arriendos, con vencimiento y renovacion | `operaciones` |
| **Administración** | Corredores, comisiones, configuración de estados | `usuarios` |

El segundo modulo es el corazon. Los otros cinco existen para que el historial
tenga algo que medir.

---

## 5. La regla de las comisiones

Las comisiones se calculan sobre el **valor pactado**, nunca sobre el precio de
lista de la ficha.

Es el error clasico de este rubro, y por eso la operación vive en un solo lugar
(`App\Services\Operaciones\CalculadoraDeComision`), con una prueba que la fija
usando dos valores distintos para que un cambio de base se detecte.

Ver [02](02-MODELO-DE-DATOS.md), seccion de comisiones.

---

## 6. Documentos

| | |
|---|---|
| [02](02-MODELO-DE-DATOS.md) | El modelo de datos, tabla por tabla |
| [03](03-MULTITENANCY-Y-ROLES.md) | Aislamiento entre inmobiliarias y roles |
| [04](04-INSTALACION.md) | Cómo se levanta |
| [05](05-CONFIGURACION.md) | Authentik, estados y reglas |
| [06](06-PRIMERA-PROPIEDAD.md) | El camino completo, de cero al tablero |
| [07](07-SEGURIDAD-Y-LEY-19628.md) | Seguridad y datos personales |
| [08](08-RESPALDOS.md) | Respaldos y restauración |
| [09](09-API.md) | La superficie de la API |
| [10](10-PRUEBAS-Y-ESTADO.md) | **Qué se ejecutó y qué no** |

---

## 7. Estado honesto del proyecto

En el entorno donde se escribio este código **no hay PHP, ni Composer, ni
Docker**. El estado es:

> **código escrito, sin ejecutar**

Lo que si se ejecuto esta marcado como tal en
[10](10-PRUEBAS-Y-ESTADO.md). Nada de lo que no se haya corrido se presenta como
probado.