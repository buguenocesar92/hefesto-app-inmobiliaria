<?php

declare(strict_types=1);

namespace App\Http\Controllers\Visitas;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Propiedad;
use App\Models\Visita;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Visitas: el seguimiento de a quien se le mostro cada propiedad.
 *
 * "El seguimiento de un cliente y sus visitas es lo que evita perder la venta por
 * olvido." Por eso `resultado` es un campo de primera clase y no una nota: es lo
 * que despues explica por que se cayo una reserva.
 *
 * Al registrar el resultado de una visita que termino en una propuesta, se
 * actualiza tambien el pivote `solicitud_propiedad`, porque el estado de
 * "visitada" es el mismo hecho visto desde el otro lado. No es una duplicacion:
 * son dos lecturas del mismo hecho, y dejarlas desalineadas produce un informe
 * de "propuestas" que no coincide con el de "visitas".
 */
class VisitaController extends Controller
{
    public function index(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', Visita::class);

        $validados = $peticion->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'cliente_id' => ['nullable', 'uuid'],
            'propiedad_id' => ['nullable', 'uuid'],
            'estado' => ['nullable', 'string', 'max:20'],
        ]);

        $usuario = $peticion->user();

        $consulta = Visita::query()
            ->with(['propiedad', 'cliente', 'corredor'])
            ->when(
                $usuario->esAdministrador() ? null : (string) $usuario->getKey(),
                fn (Builder $q, string $corredorId) => $q->where('corredor_id', $corredorId)
            )
            ->when($validados['desde'] ?? null, fn (Builder $q, string $d) => $q->where('inicio', '>=', $d))
            ->when($validados['hasta'] ?? null, fn (Builder $q, string $h) => $q->where('inicio', '<=', $h))
            ->when($validados['cliente_id'] ?? null, fn (Builder $q, string $c) => $q->where('cliente_id', $c))
            ->when($validados['propiedad_id'] ?? null, fn (Builder $q, string $p) => $q->where('propiedad_id', $p))
            ->when($validados['estado'] ?? null, fn (Builder $q, string $e) => $q->where('estado', $e));

        $visitas = $consulta->orderByDesc('inicio')->limit(200)->get();

        return response()->json([
            'datos' => $visitas->map(fn (Visita $v): array => $this->resumir($v))->all(),
        ]);
    }

    public function show(Visita $visita): JsonResponse
    {
        $this->authorize('view', $visita);

        return response()->json(['datos' => $this->resumir($visita->load(['propiedad', 'cliente', 'corredor']))]);
    }

    public function store(Request $peticion): JsonResponse
    {
        $this->authorize('create', Visita::class);

        $validados = $peticion->validate([
            'propiedad_id' => ['required', 'uuid'],
            'cliente_id' => ['required', 'uuid'],
            'inicio' => ['required', 'date'],
            'duracion_minutos' => ['nullable', 'integer', 'min:5', 'max:480'],
            'estado' => ['nullable', 'in:agendada,realizada,no_se_presento,cancelada,reprogramada'],
            'resultado' => ['nullable', 'in:interesado,muy_interesado,indeciso,descartado,otra_parte'],
            'notas' => ['nullable', 'string', 'max:5000'],
            'ofrecio_ajuste' => ['nullable', 'boolean'],
        ]);

        $propiedad = Propiedad::query()->findOrFail($validados['propiedad_id']);
        $cliente = Cliente::query()->findOrFail($validados['cliente_id']);

        $this->authorize('view', $cliente);

        // Una visita a una propiedad que ya no esta en el mercado no tiene
        // sentido operativo, y casi siempre es un error de agenda: la
        // propiedad se escribio sobre y ahi quedo la visita.
        $tieneOfertaActiva = $propiedad->ofertas->contains('activa', true);

        if (! $tieneOfertaActiva) {
            throw ValidationException::withMessages([
                'propiedad_id' => 'Esta propiedad no tiene ofertas activas en el mercado.',
            ]);
        }

        $visita = Visita::query()->create([
            'tenant_id' => $propiedad->tenant_id,
            'propiedad_id' => $propiedad->id,
            'cliente_id' => $cliente->id,
            'corredor_id' => $propiedad->corredor_id ?? (string) $peticion->user()->getKey(),
            'inicio' => $validados['inicio'],
            'duracion_minutos' => $validados['duracion_minutos'] ?? 30,
            'estado' => $validados['estado'] ?? 'agendada',
            'resultado' => $validados['resultado'] ?? null,
            'notas' => $validados['notas'] ?? null,
            'ofrecio_ajuste' => (bool) ($validados['ofrecio_ajuste'] ?? false),
        ]);

        return response()->json([
            'mensaje' => 'Visita registrada.',
            'datos' => $this->resumir($visita->load(['propiedad', 'cliente'])),
        ], 201);
    }

    /**
     * Registrar el resultado de una visita.
     *
     * Actualiza tambien el pivote de la propuesta, si la propiedad fue propuesta
     * para alguna solicitud del cliente. Ver la nota de la clase.
     */
    public function update(Request $peticion, Visita $visita): JsonResponse
    {
        $this->authorize('update', $visita);

        $validados = $peticion->validate([
            'estado' => ['sometimes', 'in:agendada,realizada,no_se_presento,cancelada,reprogramada'],
            'resultado' => ['nullable', 'in:interesado,muy_interesado,indeciso,descartado,otra_parte'],
            'notas' => ['nullable', 'string', 'max:5000'],
            'ofrecio_ajuste' => ['nullable', 'boolean'],
            'motivo_descarte' => ['nullable', 'string', 'max:255'],
        ]);

        $visita->fill($validados)->save();

        // El pivote queda alineado con la visita. Solo si la visita se marco como
        // REALIZADA; una visita cancelada no es "visitada" por el cliente.
        //
        // El par de parentesis alrededor de la comparacion es a proposito: `??`
        // tiene menor precedencia que `===`, asi que sin ellos la condicion
        // seria "el estado, o false".
        if (($validados['estado'] ?? null) === 'realizada') {
            $solicitudId = $this->solicitudDeLaVisita($visita);

            if ($solicitudId !== null) {
                \Illuminate\Support\Facades\DB::table('solicitud_propiedad')
                    ->where('tenant_id', $visita->tenant_id)
                    ->where('solicitud_id', $solicitudId)
                    ->where('propiedad_id', $visita->propiedad_id)
                    ->update(['estado' => 'visitada', 'updated_at' => now()]);
            }
        }

        return response()->json([
            'mensaje' => 'Visita actualizada.',
            'datos' => $this->resumir($visita->load(['propiedad', 'cliente'])),
        ]);
    }

    /**
     * La solicitud por la que se propuso esta propiedad a este cliente.
     */
    private function solicitudDeLaVisita(Visita $visita): ?string
    {
        $fila = \Illuminate\Support\Facades\DB::table('solicitud_propiedad')
            ->where('tenant_id', $visita->tenant_id)
            ->where('propiedad_id', $visita->propiedad_id)
            ->whereIn('solicitud_id', function ($q) use ($visita): void {
                $q->select('id')
                    ->from('solicitudes')
                    ->where('tenant_id', $visita->tenant_id)
                    ->where('cliente_id', $visita->cliente_id);
            })
            ->value('solicitud_id');

        return $fila === null ? null : (string) $fila;
    }

    /**
     * @return array<string, mixed>
     */
    private function resumir(Visita $visita): array
    {
        return [
            'id' => $visita->id,
            'propiedad_id' => $visita->propiedad_id,
            'cliente_id' => $visita->cliente_id,

            'inicio' => $visita->inicio?->toIso8601String(),
            'duracion_minutos' => $visita->duracion_minutos,
            'estado' => $visita->estado,
            'resultado' => $visita->resultado,
            'ofrecio_ajuste' => $visita->ofrecio_ajuste,
            'notas' => $visita->notas,

            'propiedad' => $visita->propiedad === null ? null : [
                'id' => $visita->propiedad->id,
                'direccion' => $visita->propiedad->direccion,
                'comuna' => $visita->propiedad->comuna,
            ],

            'cliente' => $visita->cliente === null ? null : [
                'id' => $visita->cliente->id,
                'nombre' => $visita->cliente->nombreCompleto(),
            ],

            'corredor' => $visita->corredor === null ? null : [
                'id' => $visita->corredor->id,
                'nombre' => $visita->corredor->nombre,
            ],
        ];
    }
}