<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Autenticacion
|--------------------------------------------------------------------------
|
| Un solo guard, `web`, con sesion. La API no usa token: la interfaz (Nuxt) y la
| API se sirven en el mismo origen, el navegador manda la cookie y el token CSRF
| viaja como cabecera X-XSRF-TOKEN. Ver docs/01 y docs/07.
|
| El proveedor de usuarios es Eloquent y la tabla es `usuarios`, que lleva
| `tenant_id`. El middleware ResolverTenant lee ese `tenant_id` de la sesion para
| fijar el contexto de la RLS.
|
*/

return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'usuarios',
        ],
    ],

    'providers' => [
        'usuarios' => [
            'driver' => 'eloquent',
            'model' => App\Models\Usuario::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'usuarios',
            // NO hay recuperacion de contraseno: las cuentas se administran en
            // Authentik y llegan por OIDC. Un formulario de "olvide mi
            // contraseno" aca seria una via para crear cuentas sin pasar por el
            // proveedor. Ver docs/07.
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => (int) env('AUTH_PASSWORD_TIMEOUT', 10800),

];