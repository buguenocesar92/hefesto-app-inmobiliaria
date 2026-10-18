<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operaciones;

use App\Enums\EstadoContrato;
use App\Enums\TipoOperacion;
use App\Http\Controllers\Controller;
use App\Http\Requests\CrearOperacionRequest;
use App\Http\Resources\OperacionResource;
use App\Models\Cliente;
use App\Models\Operacion;
use App\Models\Oferta;
use App\Services\Estados\RegistradorDeCambioEstado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Operaciones: reservas de venta y arriendos.
 *
 * CERRAR UNA OPERACION MUEVE LA PROPIEDAD
 * --------------------------------------
 * Crear una reserva o un arriendo no es solo escribir una fila en
 * `operaciones`: ademas hay que mover la oferta al estado que corresponde
 * ("reservada", "arrendada"), y ese movimiento escribe el historial. Por eso
 * este controlador depende del RegistradorDeCambioEstado.
 *
 * Y hay un caso que no se puede tratar igual que el resto: la RESERVA tiene
 * fecha de vencimiento, asi que ademas de crearse hay que programar su
 * liberacion. Ver LiberadorDeReservasVencidas.
 */
class OperacionController extends Controller
{
    public function __construct(private readonly RegistradorDeCambioEstado $registrador) {}

    public function index(Request $peticion): JsonResponse
    {
        $this->authorize('viewAny', Operacion::class);

        $validados = $peticion->validate([
            'tipo' => ['nullable', 'in:reserva,arriendo'],
            'estado_contrato' => ['nullable', 'string', 'max:30'],
            'cliente_id' => ['nullable', 'uuid'],
        ]);

        $usuario = $peticion->user();

        $consulta = Operacion::query()
            ->with(['propiedad', 'cliente', 'corredor'])
            ->when(
                $usuario->esAdministrador() ? null : (string) $usuario->getKey(),
                fn (Builder $q, string $corredorId) => $q->where('corredor_id', $corredorId)
            )
            ->when($validados['tipo'] ?? null, fn (Builder $q, string $t) => $q->where('tipo', $t))
            ->when($validados['estado_contrato'] ?? null, fn (Builder $q, string $e) => $q->where('estado_contrato', $e))
            ->when($validados['cliente_id'] ?? null, fn (Builder $q, string $c) => $q->where('cliente_id', $c));

        $veDinero = $usuario->esAdministrador();

        $operaciones = $consulta->orderByDesc('creada_en')->limit(100)->get();

        return response()->json([
            'datos' => $operaciones->map(function (Operacion $o) use ($veDinero): array {
                $recurso = new OperacionResource($o);
                $recurso->veDinero = $veDinero;

                return $recurso->toArray($peticion);
            })->all(),
        ]);
    }

    public function show(Request $peticion, Operacion $operacion): JsonResponse
    {
        $this->authorize('view', $operacion);

        $veDinero = $peticion->user()->can('verDinero', $operacion);

        $recurso = new OperacionResource($operacion->load(['propiedad', 'cliente', 'corredor']));
        $recurso->veDinero = $veDinero;

        return response()->json(['datos' => $recurso->toArray($peticion)]);
    }

    /**
     * Crea una reserva o un arriendo.
     *
     * La comision se calcula con la regla unica del sistema, sobre el valor
     * PACTADO. Ver App\Services\Operaciones\CalculadoraDeComision.
     */
    public function store(CrearOperacionRequest $peticion): JsonResponse
    {
        $oferta = Oferta::query()
            ->with('propiedad')
            ->findOrFail($peticion->oferta_id());

        $this->authorize('update', $oferta);

        $tipo = $peticion->tipoOperacion();

        if ($oferta->tipo_operacion !== $tipo) {
            throw ValidationException::withMessages([
                'oferta_id' => 'La oferta indicada es de ' . $oferta->tipo_operacion->etiqueta()
                    . ', y la operacion es de ' . $tipo->etiqueta() . '.',
            ]);
        }

        $cliente = Cliente::query()->findOrFail($peticion->cliente_id());

        // El cliente tiene que estar en la cartera de quien cierra la operacion.
        // Si no, el dato se esta metiendo por debajo del filtro: es la via por
        // la que un corredor meteria a un cliente de otro sin permiso.
        $this->authorize('view', $cliente);

        $operacion = Operacion::query()->create($peticion->atributosParaGuardar(
            oferta: $oferta,
            cliente: $cliente,
            corredorId: (string) $peticion->user()->getKey(),
        ));

        // El movimiento del estado de la oferta. Sin esto, la operacion
        // existiria pero la propiedad seguiria "disponible", que es
        // exactamente el estado que hace que dos corredores vendan lo mismo.
        $this->moverOfertaSegunOperacion($oferta, $tipo, $peticion->user()->getKey());

        $veDinero = $peticion->user()->can('verDinero', $operacion);

        $recurso = new OperacionResource($operacion->load(['propiedad', 'cliente', 'corredor']));
        $recurso->veDinero = $veDinero;

        return response()->json([
            'mensaje' => $tipo === TipoOperacion::Venta
                ? 'Reserva registrada.'
                : 'Arriendo registrado.',
            'datos' => $recurso->toArray($peticion),
        ], 201);
    }

    /**
     * Confirma una reserva: el cliente acepto, la operacion es efectiva.
     *
     * Solo el administrador (OperacionPolicy::confirmar). Mueve la oferta a
     * escriturado, que es un estado final.
     */
    public function confirmar(Request $peticion, Operacion $operacion): JsonResponse
    {
        $this->authorize('confirmar', $operacion);

        if (! $operacion->estado_contrato->estaVigente()) {
            throw ValidationException::withMessages([
                'estado_contrato' => 'Solo se puede confirmar una operacion vigente. Esta esta '
                    . $operacion->estado_contrato->value . '.',
            ]);
        }

        $operacion->forceFill([
            'estado_contrato' => EstadoContrato::Confirmada->value,
        ])->save();

        $oferta = $operacion->oferta;

        if ($oferta !== null) {
            $estado = $this->estadoConClaveDeSistema(
                (string) $operacion->tenant_id,
                \App\Enums\EstadoComercial::Escriturado
            );

            if ($estado !== null) {
                $this->registrador->registrar(
                    oferta: $oferta,
                    estadoNuevo: $estado,
                    motivo: 'Confirmacion de la reserva por ' . $peticion->user()->nombre,
                    usuarioId: (string) $peticion->user()->getKey(),
                );
            }
        }

        return response()->json([
            'mensaje' => 'Operacion confirmada.',
            'datos' => ['id' => $operacion->id, 'estado_contrato' => $operacion->estado_contrato->value],
        ]);
    }

    /**
     * Renueva un arriendo: se extiende la fecha de termino.
     *
     * El arriendo no "vuelve a empezar": se cuenta la renovacion y se corre el
     * termino. Un contrato con dos renovaciones es un negocio distinto de uno
     * sin renovaciones, y el tablero los separa.
     */
    public function renovar(Request $peticion, Operacion $operacion): JsonResponse
    {
        $this->authorize('confirmar', $operacion);

        if (! $operacion->esArriendo()) {
            throw ValidationException::withMessages([
                'tipo' => 'Solo un arriendo se renueva.',
            ]);
        }

        $validados = $peticion->validate([
            'meses' => ['required', 'integer', 'min:1', 'max:120'],
        ]);

        $meses = (int) $validados['meses'];

        // El nuevo termino son los meses DESDE HOY, no desde el termino viejo:
        // renovar antes de que venza tiene que conservar el tiempo que quedaba,
        // no agregar los meses al final de un contrato ya vencido.
        $nuevoTermino = $peticion->fechaActual()->addMonths($meses);

        $operacion->forceFill([
            'fecha_termino' => $nuevoTermino->toDateString(),
            'renovaciones' => (int) $operacion->renovaciones + 1,
            'estado_contrato' => EstadoContrato::Renovada->value,
        ])->save();

        return response()->json([
            'mensaje' => 'Arriendo renovado por ' . $meses . ' meses.',
            'datos' => [
                'id' => $operacion->id,
                'fecha_termino' => $operacion->fecha_termino?->toDateString(),
                'renovaciones' => $operacion->renovaciones,
                'estado_contrato' => $operacion->estado_contrato->value,
            ],
        ]);
    }

    /**
     * Mueve la oferta al estado que corresponde a la operacion recien creada.
     */
    private function moverOfertaSegunOperacion(Oferta $oferta, TipoOperacion $tipo, string $usuarioId): void
    {
        $clave = $tipo === TipoOperacion::Venta
            ? \App\Enums\EstadoComercial::Reservada
            : \App\Enums\EstadoComercial::Arrendada;

        $estado = $this->estadoConClaveDeSistema((string) $oferta->tenant_id, $clave);

        if ($estado === null) {
            throw ValidationException::withMessages([
                'oferta_id' => 'La inmobiliaria no tiene un estado para una ' . $tipo->etiqueta()
                    . '. Configure los estados en administracion.',
            ]);
        }

        $this->registrador->registrar(
            oferta: $oferta,
            estadoNuevo: $estado,
            motivo: 'Operacion creada: ' . ($tipo === TipoOperacion::Venta ? 'reserva' : 'arriendo'),
            usuarioId: $usuarioId,
        );
    }

    /**
     * El estado que el motor reconoce por su clave de sistema, si esta
     * inmobiliaria lo tiene configurado.
     */
    private function estadoConClaveDeSistema(string $tenantId, \App\Enums\EstadoComercial $clave): ?\App\Models\Estado
    {
        return \App\Models\Estado::query()
            ->where('tenant_id', $tenantId)
            ->where('clave_sistema', $clave->value)
            ->first();
    }
}