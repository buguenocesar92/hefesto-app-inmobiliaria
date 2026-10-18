<?php

declare(strict_types=1);

namespace App\Http\Controllers\Administracion;

use App\Enums\EstadoComercial;
use App\Http\Controllers\Controller;
use App\Models\Estado;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Configuracion de estados comercializables.
 *
 * Toda esta ruta detras del middleware `admin`: son los estados que sostienen
 * los informes de permanencia.
 *
 * LA REGLA DE `clave_sistema`
 * --------------------------
 * `clave_sistema` se puede ASIGNAR una vez y nunca cambiar. Es la cadena por la
 * que el motor reconoce "este estado es el disponible" o "este es el
 * reservado", y es lo que hace que la liberacion automatica de una reserva
 * funcione aunque la inmobiliaria llame a sus estados como quiera.
 *
 * Si se pudiera cambiar, una reconfiguracion rutinaria de nombres dejaria
 * reservas sin liberar sin avisar. Por eso aca se comprueba, y la razon de que
 * sea en el Request y no en la base es que el mensaje de error tiene que ser
 * entendible por quien administra, no por un motor de base.
 */
class EstadoController extends Controller
{
    public function index(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', Estado::class);

        $validados = $peticion->validate([
            'tipo_operacion' => ['nullable', 'in:venta,arriendo,ambas'],
            'activo' => ['nullable', 'boolean'],
        ]);

        $estados = Estado::query()
            ->when(
                $validados['tipo_operacion'] ?? null,
                fn ($q, string $t) => $q->where('tipo_operacion', $t)
            )
            ->when($validados['activo'] ?? null, fn ($q, bool $a) => $q->where('activo', $a))
            ->orderBy('posicion')
            ->get();

        return response()->json([
            'datos' => $estados->map(fn (Estado $e): array => $this->resumir($e))->all(),

            // Los que el motor necesita. Se devuelven aunque no esten
            // configurados, para que la pantalla de administracion diga
            // "falta este" en vez de solo no mostrarlo.
            'requeridos_por_el_motor' => array_map(
                fn (EstadoComercial $e): array => [
                    'clave' => $e->value,
                    'es_final' => $e->esTerminal(),
                ],
                EstadoComercial::cases(),
            ),
        ]);
    }

    public function show(Estado $estado): JsonResponse
    {
        $this->authorize('view', $estado);

        return response()->json(['datos' => $this->resumir($estado)]);
    }

    public function store(Request $peticion): JsonResponse
    {
        $this->authorize('create', Estado::class);

        $validados = $peticion->validate([
            'nombre' => ['required', 'string', 'max:80'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'tipo_operacion' => ['required', 'in:venta,arriendo,ambas'],
            'es_inicial' => ['nullable', 'boolean'],
            'es_final' => ['nullable', 'boolean'],
            'posicion' => ['nullable', 'integer', 'min:0'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],

            'clave_sistema' => ['nullable', 'in:' . implode(',', array_column(EstadoComercial::cases(), 'value'))],
        ]);

        $tenantId = (string) $peticion->user()->tenant_id;

        // Un solo estado inicial por tipo de operacion: si hubiera dos, el alta
        // de una oferta elegiria uno al azar y la otra quedaría como estado
        // inicial sin usar.
        if ($validados['es_inicial'] ?? false) {
            $yaExiste = Estado::query()
                ->where('tenant_id', $tenantId)
                ->where('tipo_operacion', $validados['tipo_operacion'])
                ->where('es_inicial', true)
                ->exists();

            if ($yaExiste) {
                throw ValidationException::withMessages([
                    'es_inicial' => 'Ya existe un estado inicial para ese tipo de operacion.',
                ]);
            }
        }

        $estado = Estado::query()->create([
            'tenant_id' => $tenantId,
            'nombre' => $validados['nombre'],
            'descripcion' => $validados['descripcion'] ?? null,
            'clave_sistema' => $validados['clave_sistema'] ?? null,
            'tipo_operacion' => $validados['tipo_operacion'],
            'es_inicial' => (bool) ($validados['es_inicial'] ?? false),
            'es_final' => (bool) ($validados['es_final'] ?? false),
            'posicion' => $validados['posicion'] ?? 0,
            'color' => $validados['color'] ?? '#4a6fa5',
            'activo' => true,
        ]);

        return response()->json([
            'mensaje' => 'Estado creado.',
            'datos' => $this->resumir($estado),
        ], 201);
    }

    public function update(Request $peticion, Estado $estado): JsonResponse
    {
        $this->authorize('update', $estado);

        $validados = $peticion->validate([
            'nombre' => ['sometimes', 'required', 'string', 'max:80'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'posicion' => ['nullable', 'integer', 'min:0'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'activo' => ['nullable', 'boolean'],
            'es_final' => ['nullable', 'boolean'],
        ]);

        // `clave_sistema` NO aparece en la lista de campos validables, y esa es
        // la proteccion: no hay forma de cambiarla por esta via, ni siquiera
        // mandando el mismo valor. Ver la nota de la clase.
        $estado->fill($validados)->save();

        return response()->json([
            'mensaje' => 'Estado actualizado.',
            'datos' => $this->resumir($estado),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resumir(Estado $estado): array
    {
        return [
            'id' => $estado->id,
            'nombre' => $estado->nombre,
            'descripcion' => $estado->descripcion,
            'tipo_operacion' => $estado->tipo_operacion,
            'clave_sistema' => $estado->clave_sistema,
            'es_inicial' => $estado->es_inicial,
            'es_final' => $estado->es_final,
            'posicion' => $estado->posicion,
            'color' => $estado->color,
            'activo' => $estado->activo,
            'ofertas_en_este_estado' => $estado->ofertas()->count(),
        ];
    }
}