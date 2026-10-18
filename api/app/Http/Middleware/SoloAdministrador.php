<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta las rutas de administracion para quien no sea administrador.
 *
 * Se aplica antes del controlador, y por eso devuelve 403 y no 404: es un
 * permiso de rol, no un recurso que pueda existir o no. El 404 queda para lo
 * que la Policy rechaza por cartera o por tenant, donde la existencia del
 * recurso es informacion.
 */
class SoloAdministrador
{
    public function handle(Request $peticion, Closure $siguiente): Response
    {
        $usuario = $peticion->user();

        if ($usuario === null || ! $usuario->esAdministrador()) {
            abort(403, 'Esta accion es solo para la administracion de la inmobiliaria.');
        }

        return $siguiente($peticion);
    }
}