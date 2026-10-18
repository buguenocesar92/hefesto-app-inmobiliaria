<?php

declare(strict_types=1);

namespace App\Http\Controllers\Administracion;

use App\Enums\Rol;
use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Usuarios de la inmobiliaria: corredores y administracion.
 *
 * LA CUENTA SE CREA SOLO AL PRIMER INGRESO
 * ----------------------------------------
 * No hay alta de usuarios desde aca. El usuario existe porque su equipo existe
 * en Authentik, y se crea en `AuthController::callback` la primera vez que esa
 * persona entra. Un "crear usuario" aca seria una cuenta sin identidad: alguien
 * que puede entrar en el panel y que nadie puede revocar desde el proveedor.
 *
 * Esta pantalla sirve para lo de Hefesto: cambiar el ROL y suspender.
 */
class UsuarioController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Usuario::class);

        $usuarios = Usuario::query()
            ->orderBy('nombre')
            ->get()
            ->map(fn (Usuario $u): array => [
                'id' => $u->id,
                'nombre' => $u->nombre,
                'correo' => $u->correo,
                'rol' => $u->rol->value,
                'activo' => $u->activo,
                'ultimo_ingreso' => $u->ultimo_ingreso?->toIso8601String(),
                'propiedades' => $u->propiedades()->count(),
                'clientes' => $u->clientes()->count(),
            ]);

        return response()->json(['datos' => $usuarios->all()]);
    }

    public function show(Usuario $usuario): JsonResponse
    {
        $this->authorize('view', $usuario);

        return response()->json([
            'datos' => [
                'id' => $usuario->id,
                'nombre' => $usuario->nombre,
                'correo' => $usuario->correo,
                'rol' => $usuario->rol->value,
                'activo' => $usuario->activo,
                'ultimo_ingreso' => $usuario->ultimo_ingreso?->toIso8601String(),
                'propiedades' => $usuario->propiedades()->count(),
                'clientes' => $usuario->clientes()->count(),
            ],
        ]);
    }

    /**
     * Alta de un usuario.
     *
     * Se puede SOLO si la persona ya existe en Authentik como parte del equipo de
     * esta inmobiliaria. La fila se crea "adoptando" la identidad: si la
     * verificacion no encuentra a la persona, no se crea nada.
     */
    public function store(Request $peticion): JsonResponse
    {
        $this->authorize('create', Usuario::class);

        $validados = $peticion->validate([
            'correo' => ['required', 'email', 'max:160'],
            'nombre' => ['required', 'string', 'max:160'],
            'rol' => ['required', 'in:' . implode(',', array_column(Rol::cases(), 'value'))],
        ]);

        $correo = strtolower($validados['correo']);

        $existente = Usuario::query()->where('correo', $correo)->first();

        if ($existente !== null) {
            throw ValidationException::withMessages([
                'correo' => 'Ya existe un usuario con ese correo en esta instalacion.',
            ]);
        }

        // La correlacion se arma con el prefijo de la aplicacion y el slug de la
        // inmobiliaria. Es el mismo nombre de equipo que Authentik tiene.
        $tenantId = (string) $peticion->user()->tenant_id;

        $slug = DB::table('inmobiliarias')
            ->where('id', $tenantId)
            ->value('correlacion_identidad');

        $usuario = Usuario::query()->create([
            'tenant_id' => $tenantId,
            'correlacion_identidad' => config('inmobiliaria.prefijo_equipo', 'HefestoInmobiliaria')
                . ':' . $slug . ':' . $correo,
            'nombre' => $validados['nombre'],
            'correo' => $correo,
            'rol' => $validados['rol'],
            'activo' => true,
        ]);

        return response()->json([
            'mensaje' => 'Usuario creado. La cuenta se activa al primer ingreso.',
            'datos' => [
                'id' => $usuario->id,
                'nombre' => $usuario->nombre,
                'correo' => $usuario->correo,
                'rol' => $usuario->rol->value,
            ],
        ], 201);
    }

    /**
     * Cambio de rol y suspension.
     *
     * NO SE PUEDE auto-revocar el ultimo administrador: una inmobiliaria sin
     * administrador no tiene quien administre los estados ni las comisiones, y
     * el arreglo seria entrar a la base a mano.
     */
    public function update(Request $peticion, Usuario $usuario): JsonResponse
    {
        $this->authorize('update', $usuario);

        $validados = $peticion->validate([
            'rol' => ['nullable', 'in:' . implode(',', array_column(Rol::cases(), 'value'))],
            'activo' => ['nullable', 'boolean'],
            'nombre' => ['nullable', 'string', 'max:160'],
        ]);

        $dejaDeSerAdmin = array_key_exists('rol', $validados)
            && $validados['rol'] !== Rol::Administrador->value
            && $usuario->rol === Rol::Administrador;

        $seDesactiva = array_key_exists('activo', $validados) && ! $validados['activo'];

        if (($dejaDeSerAdmin || $seDesactiva) && $this->esElUltimoAdministrador($usuario)) {
            throw ValidationException::withMessages([
                'rol' => 'No se puede dejar la inmobiliaria sin administradores activos. '
                    . 'Cambie el rol de otro administrador primero.',
            ]);
        }

        $usuario->fill($validados)->save();

        return response()->json([
            'mensaje' => 'Usuario actualizado.',
            'datos' => [
                'id' => $usuario->id,
                'nombre' => $usuario->nombre,
                'correo' => $usuario->correo,
                'rol' => $usuario->rol->value,
                'activo' => $usuario->activo,
            ],
        ]);
    }

    /**
     * ¿Es este el ultimo administrador activo de la inmobiliaria?
     */
    private function esElUltimoAdministrador(Usuario $usuario): bool
    {
        if ($usuario->rol !== Rol::Administrador) {
            return false;
        }

        $otros = Usuario::query()
            ->where('tenant_id', $usuario->tenant_id)
            ->where('id', '!=', $usuario->id)
            ->where('rol', Rol::Administrador->value)
            ->where('activo', true)
            ->count();

        return $otros === 0;
    }
}