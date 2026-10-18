<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Inmobiliaria;
use App\Models\Usuario;
use App\Services\Auth\ProveedorOidcAuthentik;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Autenticacion contra Authentik (OIDC).
 *
 * POR QUE LA SESSION Y NO UN TOKEN EN EL NAVEGADOR
 * ------------------------------------------------
 * La interfaz (Nuxt) y la API (Laravel) se sirven en el mismo origen y el
 * navegador manda la cookie automaticamente. No hay token en el codigo del
 * cliente, no hay que guardarlo, y no hay que protegerlo contra XSS.
 *
 * La sesion se crea con el equipo de Authentik: la persona tiene que pertenecer
 * al equipo de esta inmobiliaria, y de ahi sale su `tenant_id`. La sesion no
 * guarda la persona como un id suelto, guarda el usuario de la base, que ya
 * tiene su cartera y su rol.
 */
class AuthController extends Controller
{
    public function __construct(private readonly ProveedorOidcAuthentik $proveedor) {}

    /**
     * Redirige a Authentik.
     *
     * No se fija ningun tenant aca todavia: todavia no hay sesion de donde
     * sacarlo. La eleccion de inmobiliaria pasa por el equipo de Authentik, y por
     * eso `inmobiliarias` es la unica tabla sin RLS.
     */
    public function login(): JsonResponse
    {
        return $this->proveedor->redirigir();
    }

    /**
     * Callback de Authentik: crea la sesion.
     */
    public function callback(Request $peticion): JsonResponse
    {
        return $this->proveedor->callback($peticion);
    }

    public function logout(Request $peticion): JsonResponse
    {
        $peticion->session()->invalidate();
        $peticion->session()->regenerateToken();

        return response()->json(['mensaje' => 'Sesion cerrada.']);
    }

    /**
     * Quien soy, para la interfaz.
     *
     * `cartera` viene en null para el administrador: es el unico que ve toda la
     * inmobiliaria, y la interfaz lo usa para no filtrar los listados.
     */
    public function usuario(Request $peticion): JsonResponse
    {
        /** @var Usuario $usuario */
        $usuario = $peticion->user();

        $inmobiliaria = Inmobiliaria::query()->find($usuario->tenant_id);

        return response()->json([
            'datos' => [
                'id' => $usuario->id,
                'nombre' => $usuario->nombre,
                'correo' => $usuario->correo,
                'rol' => $usuario->rol->value,
                'es_administrador' => $usuario->esAdministrador(),
                'cartera' => $usuario->esAdministrador() ? null : $usuario->id,
                'inmobiliaria' => $inmobiliaria === null ? null : [
                    'id' => $inmobiliaria->id,
                    'nombre' => $inmobiliaria->nombre,
                    'slug' => $inmobiliaria->slug,
                ],
            ],
        ]);
    }
}