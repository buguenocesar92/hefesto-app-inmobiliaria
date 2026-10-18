<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Tenancy\ContextoTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fija la inmobiliaria de la peticion para que la RLS sepa a quien pertenecen las
 * filas.
 *
 * Se aplica a todo el grupo `web` (ver bootstrap/app.php) ANTES que el middleware
 * `auth` de la ruta. Por eso no puede usar `$request->user()`: resuelve la persona
 * por su cuenta con el guard `web`, que ya lee la sesion.
 *
 * Peticiones sin sesion (login, callback, salud) pasan sin transaccion. Si esas
 * rutas tocaran una tabla protegida, `public.app_tenant_id()` lanzaria excepcion y
 * la peticion fallaria: el aislamiento falla cerrado, nunca abierto.
 */
class ResolverTenant
{
    public function __construct(private readonly ContextoTenant $contexto) {}

    public function handle(Request $peticion, Closure $siguiente): Response
    {
        $usuario = auth()->guard('web')->user();

        if ($usuario === null) {
            return $siguiente($peticion);
        }

        if ($usuario->tenant_id === null) {
            // Una cuenta sin inmobiliaria no puede operar. Se corta aqui, antes
            // de tocar la base de datos.
            abort(403, 'La cuenta no esta asociada a ninguna inmobiliaria.');
        }

        return $this->contexto->ejecutarAislado(
            (string) $usuario->tenant_id,
            static fn () => $siguiente($peticion)
        );
    }
}