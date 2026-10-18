<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Prefijo de los equipos de Authentik
    |--------------------------------------------------------------------------
    |
    | El equipo de una persona tiene el nombre `<prefijo>:<correlacion>` y el de
    | una persona concreta, `<prefijo>:<correlacion>:<correo>`. El prefijo es lo
    | que permite que esta aplicacion solo reconozca a las personas que le
    | pertenecen, dentro de un Authentik compartido con las demas aplicaciones de
    | Hefesto.
    |
    | Ver docs/05.
    */
    'prefijo_equipo' => env('INMOBILIARIA_PREFIJO_EQUIPO', 'HefestoInmobiliaria'),

    /*
    |--------------------------------------------------------------------------
    | Estados que el motor necesita conocer
    |--------------------------------------------------------------------------
    |
    | No son los nombres: son las CLAVES. Cada inmobiliaria puede llamar a sus
    | estados como quiera, siempre que alguno tenga la clave `disponible` para
    | que la liberacion automatica de reservas pueda devolver una propiedad al
    | mercado.
    |
    | La semilla los crea con estos nombres; el administrador puede cambiarlos
    | despues en la pantalla de estados.
    */
    'estados_del_motor' => [
        'disponible' => ['etiqueta' => 'Disponible', 'color' => '#2f6f4e', 'es_inicial' => true],
        'reservada' => ['etiqueta' => 'Reservada', 'color' => '#b8860b', 'es_inicial' => false],
        'escriturado' => ['etiqueta' => 'Escriturado', 'color' => '#4a5568', 'es_inicial' => false],
        'arrendada' => ['etiqueta' => 'Arrendada', 'color' => '#2b6cb0', 'es_inicial' => false],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reserva
    |--------------------------------------------------------------------------
    |
    | Cuanto puede durar una reserva antes de que el sistema la libere sola.
    |
    | `dias_maximos` es un tope de seguridad: una reserva con una fecha de
    | vencimiento mas lejana que esto se va a caer, porque una reserva que espera
    | medio ano ya no es una reserva. El valor por defecto son 30 dias, que es
    | lo que se usa en el mercado chileno para unOPA con seña.
    */
    'reserva' => [
        'dias_maximos' => (int) env('INMOBILIARIA_RESERVA_DIAS_MAXIMOS', 30),
        'dias_aviso' => (int) env('INMOBILIARIA_RESERVA_DIAS_AVISO', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Arriendo
    |--------------------------------------------------------------------------
    |
    | Dias antes del termino en que un arriendo pasa a `por_renovar`. Ahi entra
    | el aviso de renovacion, que es la unica occasion en que se puede recuperar
    | un arriendo que se va a perder.
    */
    'arriendo' => [
        'dias_aviso_renovacion' => (int) env('INMOBILIARIA_ARRIENDO_DIAS_AVISO', 60),
        'duracion_maxima_meses' => (int) env('INMOBILIARIA_ARRIENDO_MESES_MAXIMOS', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Comisiones por omision
    |--------------------------------------------------------------------------
    |
    | NO son las reglas de calculo: esas viven en
    | App\Services\Operaciones\CalculadoraDeComision. Aca esta el valor con el
    | que se precarga el formulario, porque son las practicas del mercado y no
    | una decision que el sistema deba tomar.
    */
    'comision' => [
        'porcentaje_venta' => (float) env('INMOBILIARIA_COMISION_VENTA', 3.0),
        'porcentaje_arriendo_mensual' => (float) env('INMOBILIARIA_COMISION_ARRIENDO', 4.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alertas del tablero
    |--------------------------------------------------------------------------
    |
    | Desde cuantos dias una reserva se considera "colgada". Es el numero que
    | hace que la pantalla sirva: una reserva de 200 dias es un dato, una de 12
    | tambien lo es, pero solo una de las dos merece una campana.
    */
    'alertas' => [
        'reserva_dias_colgada' => (int) env('INMOBILIARIA_RESERVA_DIAS_COLGADA', 30),
        'arriendo_dias_sin_renovar' => (int) env('INMOBILIARIA_ARRIENDO_DIAS_SIN_RENOVAR', 45),
    ],

    /*
    |--------------------------------------------------------------------------
    | Almacenamiento de fotos
    |--------------------------------------------------------------------------
    |
    | Volumen donde viven las fotos. Las rutas que se guardan en la base son
    | SIEMPRE relativas a esta raiz, y la comprobacion de `FotoPropiedad::
    | rutaEsSegura()` impide que una ruta con `..` salga de aqui.
    */
    'fotos' => [
        'raiz' => env('INMOBILIARIA_FOTOS_RAIZ', '/var/www/html/storage/fotos'),
        'tamano_maximo_kb' => (int) env('INMOBILIARIA_FOTOS_TAMANO_MAXIMO_KB', 8192),
        'formatos_permitidos' => ['jpg', 'jpeg', 'png', 'webp'],
    ],
];