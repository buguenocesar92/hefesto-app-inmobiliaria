<?php

declare(strict_types=1);

namespace App\Http\Controllers\Clientes;

use App\Enums\TipoOperacion;
use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Propiedad;
use App\Models\Solicitud;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Solicitudes: lo que pide cada cliente, y las propiedades que se le ofrecen.
 *
 * ACERCA DE LA RELACION DE VARIOS A VARIOS
 * ----------------------------------------
 * Ninguno de los dos endpoints de abajo queda como un campo en una tabla:
 *
 *   - Un cliente puede tener VARIAS solicitudes activas. Por eso las solicitudes
 *     cuelgan del cliente y no al reves.
 *   - Una solicitud puede requerir VARIAS propiedades, y una propiedad puede
 *     servir para varias solicitudes. Por eso hay `solicitud_propiedad`.
 *
 * Si se hubiera puesto `solicitud_id` en `propiedades`, una propiedad con cuatro
 * solicitantes tendria que pertenecer a uno solo. Si se hubiera puesto
 * `propiedad_id` en `solicitudes`, cada solicitud podria requerir un solo
 * inmueble. Las dos simplificaciones rompen algo, y por eso no se tomo ninguna.
 */
class SolicitudController extends Controller
{
    public function index(Cliente $cliente): JsonResponse
    {
        $this->authorize('view', $cliente);

        $solicitudes = $cliente->solicitudes()
            ->with('propiedades')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'datos' => $solicitudes->map(fn (Solicitud $s): array => $this->resumir($s))->all(),
        ]);
    }

    public function show(Solicitud $solicitud): JsonResponse
    {
        $this->authorize('view', $solicitud);

        return response()->json(['datos' => $this->resumir($solicitud->load('propiedades'))]);
    }

    /**
     * Alta de una solicitud para un cliente.
     *
     * No comprueba que el presupuesto tenga sentido contra el mercado: es un
     * requerimiento del cliente, no una oferta. Lo que si se comprueba es la
     * coherencia interna del presupuesto, y eso lo hace la restriccion
     * `chk_solicitudes_presupuesto` en la base.
     */
    public function store(Request $peticion, Cliente $cliente): JsonResponse
    {
        $this->authorize('view', $cliente);

        $validados = $peticion->validate([
            'tipo_operacion' => ['required', 'in:venta,arriendo'],
            'zona' => ['nullable', 'string', 'max:120'],
            'comuna' => ['nullable', 'string', 'max:80'],
            'presupuesto_min' => ['nullable', 'integer', 'min:0'],
            'presupuesto_max' => ['nullable', 'integer', 'min:0'],
            'dormitorios_min' => ['nullable', 'integer', 'min:0', 'max:20'],
            'banos_min' => ['nullable', 'integer', 'min:0', 'max:20'],
            'superficie_min_m2' => ['nullable', 'numeric', 'gt:0'],
            'notas' => ['nullable', 'string', 'max:5000'],
            'estado' => ['nullable', 'in:activa,pausada,atendida,descartada'],
        ]);

        $solicitud = Solicitud::query()->create([
            'tenant_id' => $cliente->tenant_id,
            'cliente_id' => $cliente->id,
            'corredor_id' => $cliente->corredor_id,
            'tipo_operacion' => $validados['tipo_operacion'],
            'zona' => $validados['zona'] ?? null,
            'comuna' => $validados['comuna'] ?? null,
            'presupuesto_min' => $validados['presupuesto_min'] ?? null,
            'presupuesto_max' => $validados['presupuesto_max'] ?? null,
            'dormitorios_min' => $validados['dormitorios_min'] ?? null,
            'banos_min' => $validados['banos_min'] ?? null,
            'superficie_min_m2' => $validados['superficie_min_m2'] ?? null,
            'estado' => $validados['estado'] ?? 'activa',
            'notas' => $validados['notas'] ?? null,
        ]);

        return response()->json([
            'mensaje' => 'Solicitud registrada.',
            'datos' => $this->resumir($solicitud->load('propiedades')),
        ], 201);
    }

    public function update(Request $peticion, Solicitud $solicitud): JsonResponse
    {
        $this->authorize('update', $solicitud);

        $validados = $peticion->validate([
            'zona' => ['nullable', 'string', 'max:120'],
            'comuna' => ['nullable', 'string', 'max:80'],
            'presupuesto_min' => ['nullable', 'integer', 'min:0'],
            'presupuesto_max' => ['nullable', 'integer', 'min:0'],
            'dormitorios_min' => ['nullable', 'integer', 'min:0', 'max:20'],
            'banos_min' => ['nullable', 'integer', 'min:0', 'max:20'],
            'estado' => ['nullable', 'in:activa,pausada,atendida,descartada'],
            'notas' => ['nullable', 'string', 'max:5000'],
        ]);

        $solicitud->fill($validados)->save();

        return response()->json([
            'mensaje' => 'Solicitud actualizada.',
            'datos' => $this->resumir($solicitud->load('propiedades')),
        ]);
    }

    /**
     * Proponer una propiedad para una solicitud.
     *
     * ESCRIBE LA UNION. La escritura va con `insert` sobre `solicitud_propiedad`
     * y no con un `attach`, porque el pivote tiene datos propios (cuando se
     * propuso, quien la propuso, como le fue). Un `attach` solo puede escribir
     * dos ids.
     *
     * La propuesta NO exige que el corredor de la propiedad sea el mismo: el
     * caso normal de una inmobiliaria es que un corredor le muestre a su cliente
     * un inmueble que capturo un colega. Ver SolicitudPolicy::proponerPropiedad.
     */
    public function proponerPropiedad(Request $peticion, Solicitud $solicitud): JsonResponse
    {
        $this->authorize('proponerPropiedad', $solicitud);

        $validados = $peticion->validate([
            'propiedad_id' => ['required', 'uuid'],
        ]);

        $propiedad = Propiedad::query()->findOrFail($validados['propiedad_id']);

        // Que la propiedad sirva para esta solicitud: si la piden en venta y la
        // propiedad solo tiene oferta de arriendo, proponerla es un error de
        // la persona que la propone, no un dato valido.
        $tieneOfertaDelTipo = $propiedad->ofertas
            ->contains('tipo_operacion', TipoOperacion::from($solicitud->tipo_operacion->value));

        if (! $tieneOfertaDelTipo) {
            throw ValidationException::withMessages([
                'propiedad_id' => 'Esta propiedad no tiene una oferta de '
                    . $solicitud->tipo_operacion->etiqueta() . '.',
            ]);
        }

        $yaPropuesta = DB::table('solicitud_propiedad')
            ->where('tenant_id', $solicitud->tenant_id)
            ->where('solicitud_id', $solicitud->id)
            ->where('propiedad_id', $propiedad->id)
            ->exists();

        if ($yaPropuesta) {
            throw ValidationException::withMessages([
                'propiedad_id' => 'Esta propiedad ya fue propuesta para esta solicitud.',
            ]);
        }

        DB::table('solicitud_propiedad')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $solicitud->tenant_id,
            'solicitud_id' => $solicitud->id,
            'propiedad_id' => $propiedad->id,
            'usuario_id' => (string) $peticion->user()->getKey(),
            'propuesta_en' => now(),
            'estado' => 'pendiente',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'mensaje' => 'Propiedad propuesta.',
            'datos' => $this->resumir($solicitud->load('propiedades')),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function resumir(Solicitud $solicitud): array
    {
        return [
            'id' => $solicitud->id,
            'cliente_id' => $solicitud->cliente_id,
            'tipo_operacion' => $solicitud->tipo_operacion->value,
            'estado' => $solicitud->estado,
            'activa' => $solicitud->estaActiva(),

            'requisitos' => [
                'zona' => $solicitud->zona,
                'comuna' => $solicitud->comuna,
                'presupuesto_min' => $solicitud->presupuesto_min,
                'presupuesto_max' => $solicitud->presupuesto_max,
                'dormitorios_min' => $solicitud->dormitorios_min,
                'banos_min' => $solicitud->banos_min,
                'superficie_min_m2' => $solicitud->superficie_min_m2,
            ],

            'notas' => $solicitud->notas,

            'propiedades' => $solicitud->whenLoaded('propiedades', fn () => $solicitud->propiedades
                ->map(fn (Propiedad $p): array => [
                    'id' => $p->id,
                    'direccion' => $p->direccion,
                    'comuna' => $p->comuna,
                    'estado_propuesta' => $p->pivot?->estado,
                    'propuesta_en' => $p->pivot?->propuesta_en,
                ])->values()),
        ];
    }
}