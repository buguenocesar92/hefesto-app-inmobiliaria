<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Olvida el descubrimiento OIDC y el JWKS cacheados.
 *
 * CUANDO CORRE
 * ------------
 * Cuando Authentik rota sus claves de firma y los ingresos empiezan a fallar
 * con "la firma del id_token no es valida". El cache del JWKS dura 24 horas por
 * omision, asi que sin este comando habria que esperar.
 *
 * No es un comando de diagnostico: es la salida de emergencia de una rotacion
 * de claves. Ver docs/05.
 */
class OlvidarCacheOidcCommand extends Command
{
    protected $signature = 'inmobiliaria:olvidar-cache-oidc';

    protected $description = 'Olvida el descubrimiento OIDC y el JWKS cacheados de Authentik';

    public function handle(): int
    {
        Cache::forget('oidc:descubrimiento:' . config('authentik.issuer'));
        Cache::forget('oidc:jwks:' . config('authentik.issuer'));

        $this->info('Cache OIDC olvidada. El proximo ingreso volvera a leerla.');

        return self::SUCCESS;
    }
}