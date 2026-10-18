<?php

declare(strict_types=1);

namespace App\Http\Controllers\Clientes;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Clientes: los solicitantes.
 *
 * El listado va SIEMPRE filtrado por cartera. Para el corredor, es su filtro y
 * no hay forma de pedir otro. Para el administrador, el filtro por corredor es
 * opcional.
 *
 * Que un corredor no pueda leer clientes de otro corredor de la misma
 * inmobiliaria no es un tecnicismo: son datos personales bajo la Ley 19.628, y
 * ademas el indice comercial de un colega es informacion sensible. La prueba
 * `CarteraDeCorredorTest` lo fija.
 */
class ClienteController extends Controller
{
    public function index(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', Cliente::class);

        $validados = $peticion->validate([
            'activo' => ['nullable', 'boolean'],
            'corredor_id' => ['nullable', 'uuid'],
            'buscar' => ['nullable', 'string', 'max:120'],
        ]);

        $usuario = $peticion->user();

        $consulta = Cliente::query()
            ->withCount(['solicitudes', 'visitas'])
            ->when(
                $usuario->esAdministrador() ? ($validados['corredor_id'] ?? null) : (string) $usuario->getKey(),
                fn (Builder $q, string $corredorId) => $q->where('clientes.corredor_id', $corredorId)
            )
            ->when($validados['activo'] ?? null, fn (Builder $q, bool $a) => $q->where('activo', $a))
            ->when($validados['buscar'] ?? null, function (Builder $q) use ($validados): void {
                $b = $validados['buscar'];
                $q->where(function (Builder $sub) use ($b): void {
                    $sub->where('nombres', 'ilike', '%' . $b . '%')
                        ->orWhere('apellidos', 'ilike', '%' . $b . '%')
                        ->orWhere('telefono', 'ilike', '%' . $b . '%');
                });
            });

        $clientes = $consulta->orderByDesc('created_at')->paginate(30);

        return response()->json([
            'datos' => collect($clientes->items())->map(fn (Cliente $c): array => $this->resumir($c))->all(),
            'meta' => [
                'pagina_actual' => $clientes->currentPage(),
                'por_pagina' => $clientes->perPage(),
                'total' => $clientes->total(),
                'ultima_pagina' => $clientes->lastPage(),
            ],
        ]);
    }

    public function show(Cliente $cliente): JsonResponse
    {
        $this->authorize('view', $cliente);

        $cliente->load(['corredor', 'solicitudes.propiedades']);

        return response()->json([
            'datos' => $this->resumir($cliente),
            'solicitudes' => $cliente->solicitudes->map(fn ($s): array => [
                'id' => $s->id,
                'tipo_operacion' => $s->tipo_operacion->value,
                'estado' => $s->estado,
                'comuna' => $s->comuna,
                'zona' => $s->zona,
                'presupuesto_min' => $s->presupuesto_min,
                'presupuesto_max' => $s->presupuesto_max,
                'dormitorios_min' => $s->dormitorios_min,
                'propiedades_propuestas' => $s->propiedades->count(),
            ])->values(),
        ]);
    }

    public function store(Request $peticion): JsonResponse
    {
        $this->authorize('create', Cliente::class);

        $validados = $peticion->validate([
            'nombres' => ['required', 'string', 'max:80'],
            'apellidos' => ['required', 'string', 'max:80'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:160'],
            'notas' => ['nullable', 'string', 'max:5000'],

            // El consentimiento se pide siempre. No es un campo opcional de la
            // ficha: es la base para guardar el telefono y el correo. Ver
            // docs/07 y la Ley 19.628.
            'consentimiento_contacto' => ['required', 'boolean'],

            'corredor_id' => ['nullable', 'uuid'],
        ]);

        $usuario = $peticion->user();

        $cliente = Cliente::query()->create([
            'tenant_id' => $usuario->tenant_id,
            'corredor_id' => $usuario->esAdministrador()
                ? ($validados['corredor_id'] ?? $usuario->getKey())
                : $usuario->getKey(),
            'nombres' => $validados['nombres'],
            'apellidos' => $validados['apellidos'],
            'telefono' => $validados['telefono'] ?? null,
            'correo' => $validados['correo'] ?? null,
            'notas' => $validados['notas'] ?? null,
            'consentimiento_contacto' => (bool) $validados['consentimiento_contacto'],
            'consentimiento_fecha' => $validados['consentimiento_contacto'] ? now() : null,
            'activo' => true,
        ]);

        return response()->json([
            'mensaje' => 'Cliente registrado.',
            'datos' => $this->resumir($cliente->load('corredor')),
        ], 201);
    }

    public function update(Request $peticion, Cliente $cliente): JsonResponse
    {
        $this->authorize('update', $cliente);

        $validados = $peticion->validate([
            'nombres' => ['sometimes', 'required', 'string', 'max:80'],
            'apellidos' => ['sometimes', 'required', 'string', 'max:80'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:160'],
            'notas' => ['nullable', 'string', 'max:5000'],
            'activo' => ['nullable', 'boolean'],
        ]);

        // El `corredor_id` NO se puede cambiar por esta via. Traspasar un
        // cliente cambia de manos informacion personal y tiene que ser una
        // accion de administracion y quedar registrada.
        $cliente->fill($validados)->save();

        return response()->json([
            'mensaje' => 'Cliente actualizado.',
            'datos' => $this->resumir($cliente->load('corredor')),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resumir(Cliente $cliente): array
    {
        return [
            'id' => $cliente->id,
            'nombres' => $cliente->nombres,
            'apellidos' => $cliente->apellidos,
            'nombre_completo' => $cliente->nombreCompleto(),
            'telefono' => $cliente->telefono,
            'correo' => $cliente->correo,
            'notas' => $cliente->notas,
            'activo' => $cliente->activo,

            'consentimiento_contacto' => $cliente->consentimiento_contacto,
            'consentimiento_fecha' => $cliente->consentimiento_fecha?->toIso8601String(),

            'corredor' => $cliente->corredor === null ? null : [
                'id' => $cliente->corredor->id,
                'nombre' => $cliente->corredor->nombre,
            ],

            'solicitudes' => $cliente->whenCounted('solicitudes'),
            'visitas' => $cliente->whenCounted('visitas'),
        ];
    }
}