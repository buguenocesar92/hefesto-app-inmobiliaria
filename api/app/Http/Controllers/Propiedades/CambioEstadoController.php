<?php

declare(strict_types=1);

namespace App\Http\Controllers\Propiedades;

use App\Http\Controllers\Controller;
use App\Http\Requests\CambiarEstadoRequest;
use App\Http\Resources\CambioEstadoResource;
use App\Models\Oferta;
use App\Services\Estados\RegistradorDeCambioEstado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cambios de estado de una oferta: el corazon del producto.
 *
 * TODA la escritura en `cambios_estado` pasa por aca, y de ahi al
 * RegistradorDeCambioEstado. No hay ninguna otra via.
 */
class CambioEstadoController extends Controller
{
    public function __construct(private readonly RegistradorDeCambioEstado $registrador) {}

    /**
     * El historial de una oferta.
     *
     * Devuelve todos los tramos, del mas reciente al mas antiguo. El tramo
     * abierto viene con `hasta` en null y `dias()` calculado hasta hoy, que es
     * lo que la linea de tiempo necesita para dibujar el estado vigente.
     */
    public function index(Request $peticion, Oferta $oferta): JsonResponse
    {
        $this->authorize('verHistorial', $oferta);

        $tramos = $oferta->cambiosEstado()
            ->with(['estadoNuevo', 'estadoAnterior', 'usuario'])
            ->get();

        return response()->json([
            'datos' => CambioEstadoResource::collection($tramos),
            'oferta_id' => $oferta->id,
            'tipo_operacion' => $oferta->tipo_operacion->value,
            'dias_en_estado_actual' => $oferta->diasEnEstado(),
        ]);
    }

    /**
     * Mueve la oferta a un estado nuevo.
     *
     * El cambio se escribe con el id del USUARIO autenticado, nunca con uno que
     * venga en el cuerpo de la peticion. Es un detalle de seguridad, no una
     * comodidad: si el id viniera del cliente, cualquiera podria escribir en la
     * historia que el cambio lo hizo un colega.
     */
    public function store(CambiarEstadoRequest $peticion, Oferta $oferta): JsonResponse
    {
        $this->authorize('cambiarEstado', $oferta);

        $estado = $peticion->estado_destino();

        $tramo = $this->registrador->registrar(
            oferta: $oferta,
            estadoNuevo: $estado,
            motivo: $peticion->motivo(),
            usuarioId: (string) $peticion->user()->getKey(),
        );

        // Se recarga la oferta para que el recurso salga con el estado ya
        // actualizado, y no con el que tenia antes del cambio.
        $oferta->refresh();

        return response()->json([
            'mensaje' => 'Estado actualizado.',
            'tramo' => new CambioEstadoResource($tramo->load(['estadoNuevo', 'estadoAnterior', 'usuario'])),
            'oferta' => [
                'id' => $oferta->id,
                'estado_id' => $oferta->estado_id,
                'estado_nombre' => $estado->nombre,
                'estado_color' => $estado->color,
                'estado_desde' => $oferta->estado_desde?->toIso8601String(),
                'dias_en_estado' => $oferta->diasEnEstado(),
            ],
        ], 201);
    }
}