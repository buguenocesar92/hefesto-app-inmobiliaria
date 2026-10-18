<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Sesion
|--------------------------------------------------------------------------
|
| El tiempo de vida lo fija `SESSION_EXPIRACION_MINUTOS`, que por omision son 120.
|
| POR QUE 120 Y NO UN DIA
| -----------------------
| Una sesion abierta mucho rato es una sesion abierta a la cartera de una
| inmobiliaria: los nombres de los clientes, las reservas y el valor pactado de
| las operaciones. Dos horas es el tiempo de una jornada de trabajo, y pasado ese
| plazo hay que volver a autenticarse.
|
| `SameSite=Lax` y no `Strict` porque el retorno desde Authentik es una
 * navegacion que viene de otro origen: con `Strict`, la cookie de sesion no
 * viajaria en ese primer POST y el ingreso fallaria.
|
| Ver docs/07.
|
*/

return [

    'driver' => env('SESSION_DRIVER', 'database'),

    'lifetime' => (int) env('SESSION_EXPIRACION_MINUTOS', 120),

    'expire_on_close' => false,

    'encrypt' => false,

    'files' => storage_path('framework/sessions'),

    'connection' => env('SESSION_CONNECTION'),

    'table' => 'sessions',

    'store' => env('SESSION_STORE'),

    'lottery' => [2, 100],

    'cookie' => env('SESSION_COOKIE', 'inmobiliaria_sesion'),

    'path' => '/',

    'domain' => env('SESSION_DOMAIN'),

    // En produccion tiene que ser `secure`: sobre una conexion que no es TLS,
    // la cookie de sesion viaja en claro y sirve para suplantar a la persona
    // que esta trabajando. Ver docs/07.
    'secure' => (bool) env('SESSION_SECURE_COOKIE', false),

    'http_only' => true,

    'same_site' => env('SESSION_SAME_SITE', 'lax'),

    'partitioned' => false,

];