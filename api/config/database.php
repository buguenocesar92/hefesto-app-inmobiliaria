<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Base de datos
|--------------------------------------------------------------------------
|
| PostgreSQL es OBLIGATORIO en esta aplicacion, no una preferencia. La Row
| Level Security que aisla una inmobiliaria de otra es una funcion de
| PostgreSQL: en otro motor, el aislamiento no existiria y las pruebas de
| `Aislamiento` pasarian sin comprobar nada.
|
| Ver docs/02 y docs/03.
|
*/

return [

    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'postgres'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'inmobiliaria'),
            'username' => env('DB_USERNAME', 'inmobiliaria'),
            'password' => env('DB_PASSWORD', ''),

            // `prefer` dentro de la red de Compose. Si la base queda afuera de la
            // instalacion, use `require`: sin cifrado, el permiso de la RLS
            // viaja en claro. Ver docs/07.
            'sslmode' => env('DB_SSLMODE', 'prefer'),

            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,

            // Falla de verdad si la conexion se cae. Es importante aqui: con
            // `false`, Laravel devuelve un error de base de datos generico que
            // se confunde con un problema de RLS, que es justo el diagnostico
            // que peor se pierde.
            'strict' => true,
            'engine' => null,

            // El pool se dimensiona una sola vez, para todas las inmobiliarias.
            // Con esquema por cliente habria que dimensionarlo por
            // combinacion, que es una de las razones por las que el portal de
            // autoservicio descarto ese enfoque. Ver docs/03.
            'options' => [
                'application_name' => 'hefesto-inmobiliaria',
            ],
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [

        'client' => env('REDIS_CLIENT', 'predis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', 'hefesto_inmobiliaria_'),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', 'redis'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', 'redis'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];