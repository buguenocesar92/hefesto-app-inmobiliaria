<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Emisor de Authentik
    |--------------------------------------------------------------------------
    |
    | URL publica del emisor, SIN barra final. El descubrimiento OIDC se lee de
    | `<issuer>/.well-known/openid-configuration`.
    */
    'issuer' => env('AUTHENTIK_ISSUER', 'https://identidad.hefesto.cl'),

    /*
    |--------------------------------------------------------------------------
    | Credenciales
    |--------------------------------------------------------------------------
    |
    | Cliente OIDC EXCLUSIVO de esta aplicacion: asi se puede revocar el acceso a
    | Hefesto Inmobiliaria sin tocar las demas aplicaciones de Hefesto.
    |
    | El valor de ejemplo esta vacio a proposito: una clave real en un archivo de
    | ejemplo es una clave filtrada.
    */
    'client_id' => env('AUTHENTIK_CLIENT_ID', ''),
    'client_secret' => env('AUTHENTIK_CLIENT_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Redireccion
    |--------------------------------------------------------------------------
    |
    | Debe coincidir EXACTAMENTE con la URL de retorno configurada en Authentik.
    */
    'redirect_uri' => env('AUTHENTIK_REDIRECT_URI', 'http://localhost:8000/api/v1/auth/callback'),

    /*
    |--------------------------------------------------------------------------
    | Alcance solicitado
    |--------------------------------------------------------------------------
    |
    | `groups` es lo que permite resolver la inmobiliaria: la pertenencia se
    | expresa con EQUIPOS, no por el dominio del correo, porque una inmobiliaria
    | puede tener correos de varios dominios y una persona puede trabajar para
    | dos inmobiliarias.
    */
    'scopes' => env('AUTHENTIK_SCOPES', 'openid profile email groups'),

    'algoritmo' => env('AUTHENTIK_ALGORITMO', 'RS256'),

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | El descubrimiento cambia muy seguido y no hace falta pedirlo en cada
    | ingreso. El JWKS se cachea mas tiempo porque las claves rotan con mucha
    | menos frecuencia; si una rotacion deja invalido el cache, el ingreso falla
    | con un error claro y se corrige con `inmobiliaria:olvidar-cache-oidc`.
    */
    'cache_metadata' => (int) env('AUTHENTIK_CACHE_METADATA', 3600),
    'cache_jwks' => (int) env('AUTHENTIK_CACHE_JWKS', 86400),

    /*
    |--------------------------------------------------------------------------
    | Margen de tolerancia
    |--------------------------------------------------------------------------
    |
    | Segundos de margen al comprobar `exp`. Un margen pequeno es lo correcto en
    * un servidor bien sincronizado.
    */
    'leeway' => (int) env('AUTHENTIK_LEEWAY', 60),
];