<?php

declare(strict_types=1);

namespace App\Http\Controllers\Propiedades;

use App\Enums\EstadoComercial;
use App\Enums\TipoOperacion;
use App\Http\Controllers\Controller;
use App\Models\Estado;
use App\Models\Oferta;
use App\Models\Propiedad;
use App\Services\Estados\RegistradorDeCambioEstado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Ofertas: la presencia de una propiedad en un mercado.
 *
 * AL DAR DE ALTA UNA OFERTA
 * -----------------------
 * Se elige el estado inicial por su `es_inicial`, y se escribe el PRIMER TRAMO
 * del historial con `estado_anterior_id` en NULL.
 *
 * No se deja el historial sin arrancar. Una oferta con estado pero sin tramo
 * inicial no tiene forma de medir permanencia, y el producto se apoya desde
 * ese primer tramo. Si el estado inicial no existe o no es valido para el tipo
 * de operacion, el alta falla: es mejor no tener oferta que tener una oferta
 * cuya linea de tiempo arranca a destiempo.
 */
class OfertaController extends Controller
{
    public function __construct(private readonly RegistradorDeCambioEstado $registrador) {}

    public function index(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', Oferta::class);

        $validados = $peticion->validate([
            'tipo_operacion' => ['nullable', 'in:venta,arriendo'],
            'estado_id' => ['nullable', 'uuid'],
            'propiedad_id' => ['nullable', 'uuid'],
        ]);

        $usuario = $peticion->user();

        $consulta = Oferta::query()
            ->with(['propiedad', 'estado', 'corredor'])
            // El corredor solo ve sus ofertas.
            ->when(
                $usuario->esAdministrador() ? null : (string) $usuario->getKey(),
                fn ($q, string $corredorId) => $q->where('corredor_id', $corredorId)
            )
            ->when($validados['tipo_operacion'] ?? null, fn ($q, string $t) => $q->where('tipo_operacion', $t))
            ->when($validados['estado_id'] ?? null, fn ($q, string $e) => $q->where('estado_id', $e))
            ->when($validados['propiedad_id'] ?? null, fn ($q, string $p) => $q->where('propiedad_id', $p));

        return response()->json([
            'datos' => $consulta->orderByDesc('estado_desde')->limit(100)->get()
                ->map(fn (Oferta $oferta): array => $this->resumir($oferta)),
        ]);
    }

    public function show(Oferta $oferta): JsonResponse
    {
        $this->authorize('view', $oferta);

        $oferta->load(['propiedad.estado', 'estado', 'corredor']);

        return response()->json(['datos' => $this->resumir($oferta)]);
    }

    /**
     * Da de alta una oferta para una propiedad.
     *
     * La restriccion `uq_ofertas_propiedad_tipo` garantiza que la propiedad no
     * tenga dos ofertas del mismo tipo. Aca se traduce a un 422 con un mensaje
     * que dice que ya existe, que es informacion util para quien lo intenta.
     */
    public function store(Request $peticion): JsonResponse
    {
        $this->authorize('create', Oferta::class);

        $validados = $peticion->validate([
            'propiedad_id' => ['required', 'uuid'],
            'tipo_operacion' => ['required', 'in:venta,arriendo'],
            'valor_lista' => ['nullable', 'integer', 'min:0'],
        ]);

        $usuario = $peticion->user();
        $tipo = TipoOperacion::from($validados['tipo_operacion']);

        $propiedad = Propiedad::query()->findOrFail($validados['propiedad_id']);

        $this->authorize('update', $propiedad);

        $estadoInicial = $this->estadoInicialDe((string) $usuario->tenant_id, $tipo);

        if ($estadoInicial === null) {
            throw ValidationException::withMessages([
                'tipo_operacion' => 'La inmobiliaria no tiene un estado inicial para '
                    . $tipo->etiqueta() . '. Configurelos en administracion.',
            ]);
        }

        $yaExiste = Oferta::query()
            ->where('propiedad_id', $propiedad->id)
            ->where('tipo_operacion', $tipo->value)
            ->exists();

        if ($yaExiste) {
            throw ValidationException::withMessages([
                'tipo_operacion' => 'Esta propiedad ya tiene una oferta de ' . $tipo->etiqueta() . '.',
            ]);
        }

        $oferta = Oferta::query()->create([
            'tenant_id' => $propiedad->tenant_id,
            'propiedad_id' => $propiedad->id,
            'corredor_id' => $propiedad->corredor_id,
            'tipo_operacion' => $tipo->value,
            'estado_id' => $estadoInicial->id,
            'estado_desde' => now(),
            'valor_lista' => $validados['valor_lista'] ?? $propiedad->valor_venta,
            'visible_en_portal' => true,
            'activa' => true,
        ]);

        // El primer tramo. Sin el, la oferta no tendria desde cuando medir.
        $this->registrador->registrarPrimerTramo(
            oferta: $oferta,
            estadoInicial: $estadoInicial,
        );

        $oferta->load(['propiedad', 'estado', 'corredor']);

        return response()->json([
            'mensaje' => 'Oferta creada en ' . $tipo->etiqueta() . '.',
            'datos' => $this->resumir($oferta),
        ], 201);
    }

    /**
     * Cambios que NO son de estado: precio de lista, visibilidad, corredor.
     *
     * El estado no se toca por aca. Cambiarlo es `CambioEstadoController`, y la
     * razon esta ahi: escribir el historial.
     */
    public function update(Request $peticion, Oferta $oferta): JsonResponse
    {
        $this->authorize('update', $oferta);

        $validados = $peticion->validate([
            'valor_lista' => ['nullable', 'integer', 'min:0'],
            'visible_en_portal' => ['nullable', 'boolean'],
            'activa' => ['nullable', 'boolean'],
        ]);

        // `estado_id` y `estado_desde` no estan en la lista de campos validos a
        // proposito: no se pueden cambiar por la puerta de atras de un update.
        $oferta->fill($validados)->save();
        $oferta->load(['propiedad', 'estado', 'corredor']);

        return response()->json([
            'mensaje' => 'Oferta actualizada.',
            'datos' => $this->resumir($oferta),
        ]);
    }

    /**
     * El estado inicial del flujo, para un tipo de operacion.
     */
    private function estadoInicialDe(string $tenantId, TipoOperacion $tipo): ?Estado
    {
        return Estado::query()
            ->where('tenant_id', $tenantId)
            ->where('es_inicial', true)
            ->where(fn ($q) => $q->where('tipo_operacion', $tipo->value)
                ->orWhere('tipo_operacion', 'ambas'))
            ->orderBy('posicion')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function resumir(Oferta $oferta): array
    {
        $estadoClave = $oferta->estado?->claveSistema();

        return [
            'id' => $oferta->id,
            'tipo_operacion' => $oferta->tipo_operacion->value,
            'valor_lista' => $oferta->valor_lista,
            'visible_en_portal' => $oferta->visible_en_portal,
            'activa' => $oferta->activa,

            'estado' => $oferta->estado === null ? null : [
                'id' => $oferta->estado->id,
                'nombre' => $oferta->estado->nombre,
                'color' => $oferta->estado->color,
                'clave_sistema' => $oferta->estado->clave_sistema,
                // El motor necesita distinguir "retirada del mercado" de
                // "en venta": si la oferta esta en un estado final, no se
                // muestra en el portal.
                'es_final' => $estadoClave?->esTerminal() ?? $oferta->estado->es_final,
            ],

            'estado_desde' => $oferta->estado_desde?->toIso8601String(),
            'dias_en_estado' => $oferta->diasEnEstado(),

            'propiedad' => $oferta->propiedad === null ? null : [
                'id' => $oferta->propiedad->id,
                'direccion' => $oferta->propiedad->direccion,
                'comuna' => $oferta->propiedad->comuna,
            ],

            'corredor' => $oferta->corredor === null ? null : [
                'id' => $oferta->corredor->id,
                'nombre' => $oferta->corredor->nombre,
            ],
        ];
    }
}