<?php

declare(strict_types=1);

namespace App\Http\Controllers\Propiedades;

use App\Enums\TipoOperacion;
use App\Http\Controllers\Controller;
use App\Http\Requests\GuardarPropiedadRequest;
use App\Http\Resources\PropiedadResource;
use App\Models\Propiedad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

use Illuminate\Database\Eloquent\Builder;

/**
 * Propiedades: el inventario.
 *
 * POR QUE EL LISTADO LLEVA `corredor_id` DE LA CONSULTA Y NO DE LA POLICY
 * ------------------------------------------------------------------
 * Porque son cosas distintas. La policy responde "puedo ver ESTA propiedad", que
 * se evalua por recurso. El listado tiene que devolver solo las que el usuario
 * puede ver, y filtrar despues de traer todas las filas seria traer el
 * inventario de la inmobiliaria entera a memoria para descartar la mayor parte.
 *
 * El filtro por corredor va en la consulta, entonces. Y se aplica SIEMPRE para
 * el corredor; para el administrador es opcional, por parametro.
 */
class PropiedadController extends Controller
{
    public function index(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', Propiedad::class);

        $validados = $peticion->validate([
            'comuna' => ['nullable', 'string', 'max:80'],
            'tipo_propiedad' => ['nullable', 'string', 'max:40'],
            'tipo_operacion' => ['nullable', 'in:venta,arriendo'],
            'corredor_id' => ['nullable', 'uuid'],
            'activa' => ['nullable', 'boolean'],
            'buscar' => ['nullable', 'string', 'max:120'],
        ]);

        $usuario = $peticion->user();

        $consulta = Propiedad::query()
            ->with(['ofertas.estado', 'corredor', 'fotos'])
            ->when(
                // El corredor no puede pedir el filtro por corredor: si lo
                // pudiera, veria el inventario de un colega.
                $usuario->esAdministrador() ? $peticion->input('corredor_id') : (string) $usuario->getKey(),
                fn (Builder $q, string $corredorId) => $q->where('propiedades.corredor_id', $corredorId)
            );

        $consulta
            ->when($validados['comuna'] ?? null, fn (Builder $q, string $c) => $q->where('comuna', $c))
            ->when($validados['tipo_propiedad'] ?? null, fn (Builder $q, string $t) => $q->where('tipo_propiedad', $t))
            ->when($validados['activa'] ?? null, fn (Builder $q, bool $a) => $q->where('activa', $a))
            ->when(
                $validados['buscar'] ?? null,
                fn (Builder $q, string $b) => $q->where(function (Builder $sub) use ($b): void {
                    $sub->where('direccion', 'ilike', '%' . $b . '%')
                        ->orWhere('codigo_interno', 'ilike', '%' . $b . '%');
                })
            )
            ->when(
                // Filtrar por tipo de operacion es filtrar las ofertas: la
                // propiedad no sabe si esta en venta, son las ofertas las que
                // lo saben.
                $validados['tipo_operacion'] ?? null,
                fn (Builder $q, string $tipo) => $q->whereHas(
                    'ofertas',
                    fn (Builder $o) => $o->where('tipo_operacion', $tipo)->where('activa', true)
                )
            );

        $propiedades = $consulta->orderByDesc('created_at')->paginate(30);

        return response()->json([
            'datos' => PropiedadResource::collection($propiedades->items()),
            'meta' => [
                'pagina_actual' => $propiedades->currentPage(),
                'por_pagina' => $propiedades->perPage(),
                'total' => $propiedades->total(),
                'ultima_pagina' => $propiedades->lastPage(),
            ],
        ]);
    }

    public function show(Propiedad $propiedad): JsonResponse
    {
        $this->authorize('view', $propiedad);

        $propiedad->load(['ofertas.estado', 'corredor', 'fotos']);

        return response()->json([
            'datos' => new PropiedadResource($propiedad),
            'tipo_operacion_disponible' => [
                TipoOperacion::Venta->value,
                TipoOperacion::Arriendo->value,
            ],
        ]);
    }

    public function store(GuardarPropiedadRequest $peticion): JsonResponse
    {
        $this->authorize('create', Propiedad::class);

        $propiedad = Propiedad::query()->create(
            $peticion->atributosParaGuardar((string) $peticion->user()->tenant_id)
        );

        $propiedad->load(['ofertas.estado', 'corredor', 'fotos']);

        return response()->json([
            'mensaje' => 'Propiedad registrada.',
            'datos' => new PropiedadResource($propiedad),
        ], 201);
    }

    public function update(GuardarPropiedadRequest $peticion, Propiedad $propiedad): JsonResponse
    {
        $this->authorize('update', $propiedad);

        $propiedad->fill($peticion->atributosParaGuardar((string) $propiedad->tenant_id));
        $propiedad->save();

        $propiedad->load(['ofertas.estado', 'corredor', 'fotos']);

        return response()->json([
            'mensaje' => 'Propiedad actualizada.',
            'datos' => new PropiedadResource($propiedad),
        ]);
    }
}