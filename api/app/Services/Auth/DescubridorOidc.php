<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Descubrimiento OIDC contra Authentik.
 *
 * Lee `<issuer>/.well-known/openid-configuration` y guarda el resultado en
 * cache. Se cachea porque se consulta en cada ingreso y Authentik no cambia sus
 * endpoints seguido: sin cache, cada login es una llamada extra a un servicio que
 * ya se sabe lento.
 *
 * ESTADO: NO VERIFICADO. En el entorno donde se escribio esto no hay PHP ni un
 * Authentik al que consultarse, asi que la forma del documento y los nombres de
 * los campos NO se comprobaron contra un servidor real. Los nombres de campo son
 * los del descubrimiento OIDC estandar. Ver docs/10.
 */
class DescubridorOidc
{
    /**
     * Metadatos del proveedor, desde la cache cuando se puede.
     *
     * @return object con los campos del documento de descubrimiento.
     */
    public function metadatos(): object
    {
        $clave = 'oidc:descubrimiento:' . config('authentik.issuer');

        $cacheado = Cache::get($clave);

        if ($cacheado !== null) {
            return $cacheado;
        }

        $url = rtrim((string) config('authentik.issuer'), '/')
            . '/.well-known/openid-configuration';

        $respuesta = Http::timeout(10)
            ->accept('application/json')
            ->get($url);

        if (! $respuesta->successful()) {
            throw new RuntimeException(
                'No se pudo leer el descubrimiento OIDC de Authentik (' . $url . '). '
                . 'Estado devuelto: ' . $respuesta->status() . '. Ver docs/05.'
            );
        }

        $metadatos = $respuesta->json();

        if (! is_array($metadatos)) {
            throw new RuntimeException(
                'El descubrimiento OIDC de Authentik no devolvio un objeto JSON valido. Ver docs/05.'
            );
        }

        Cache::put($clave, (object) $metadatos, (int) config('authentik.cache_metadata', 3600));

        return (object) $metadatos;
    }
}