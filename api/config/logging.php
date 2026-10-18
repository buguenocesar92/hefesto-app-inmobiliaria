<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Registro (logging)
|--------------------------------------------------------------------------
|
| NOTA QUE IMPORTA EN ESTA APLICACION
| -----------------------------------
| `LOG_LEVEL` en produccion tiene que ser `info` o mas alto. Con `debug`, el
| registro puede incluir el CUERPO de las peticiones, y en una inmobiliaria el
| cuerpo de un POST de clientes o de operaciones contiene datos personales bajo
| la Ley 19.628.
|
| Lo que se registra a nivel `info`: la peticion, su duracion y su estado. Sin
| cuerpo. Lo que queda fuera: el cuerpo de la peticion y los valores enviados.
|
| Ver docs/07.
|
*/

return [

    'default' => env('LOG_CHANNEL', 'stack'),

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single,stderr')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/inmobiliaria.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'replace_placeholders' => true,
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => Monolog\Handler\StreamHandler::class,
            'formatter' => Monolog\Formatter\JsonFormatter::class,
            'with' => [
                'stream' => 'php://stderr',
            ],
        ],

        /*
        |----------------------------------------------------------------------
        | Canal de auditoria
        |----------------------------------------------------------------------
        | Aparte del registro general, y con su propio archivo. Un cambio de
        | estado de una oferta y una liberacion automatica de reserva vencida
        | tienen que poder leerse seis meses despues, aunque el registro general
        | ya se haya rotado.
        |
        | OJO: este canal escribe en la misma base y por lo tanto esta sujeto a
        | la RLS. Un evento de escritura de una inmobiliaria A queda invisible
        | para la B, que es lo correcto. Ver docs/03.
        */
        'auditoria' => [
            'driver' => 'daily',
            'path' => storage_path('logs/auditoria.log'),
            'level' => 'info',
            'days' => 365,
            'replace_placeholders' => true,
        ],

    ],

];