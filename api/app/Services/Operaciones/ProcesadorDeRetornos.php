<?php

declare(strict_types=1);

namespace App\Services\Operaciones;

use App\Enums\EstadoContrato;
use App\Models\Operacion;
use App\Services\Estados\RegistradorDeCambioEstado;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Procesador de retornos de arriendo.
 *
 * "Un arriendo que termina y no se renueva es un retorno, no un evento neutro."
 *
 * Lo que cambia respecto a una baja de cualquier contrato es que el retorno
 * DEJA MEDIRSE: la propiedad vuelve al mercado y hay que poder decir cuanto
 * tarda en arrendar de nuevo. Por eso el retorno hace el mismo movimiento que
 * una reserva vencida (un cambio de estado en `cambios_estado`) pero se cuenta
 * aparte en los informes, porque "se cayo una reserva" y "se termino un
 * arriendo" son problemas de negocio distintos.
 *
 * QUE NO HACE
 * -----------
 * No cancela nada por su cuenta ni avisa a nadie: solo deja el estado en la base
 * coherente. La notificacion al corredor va en el trabajo de cola
 * `AvisarRetornosDeArriendo`, que es reintentable y se puede recuperar.
 */
class ProcesadorDeRetornos
{
    public function __construct(private readonly RegistradorDeCambioEstado $registrador) {}

    /**
     * Marca como retornados los arriendos cuyo contrato termino sin renovar.
     *
     * Un arriendo NO se cancela a la distancia: se marca `por_renovar` cuando
     * se acerca el vencimiento (por el aviso de renovacion), y solo si llega
     * al termino sin decision, queda `retornado`.
     *
     * @return Collection<int, array{operacion_id: string, oferta_id: string, dias_arrendados: int, renovaciones: int}>
     */
    public function procesarPara(string $tenantId, ?Carbon $hoy = null): Collection
    {
        $hoy ??= Carbon::now();

        return DB::transaction(function () use ($tenantId, $hoy): Collection {
            $retornados = collect();

            $operaciones = Operacion::query()
                ->where('tenant_id', $tenantId)
                ->where('tipo', 'arriendo')
                ->whereIn('estado_contrato', [
                    EstadoContrato::Vigente->value,
                    EstadoContrato::PorRenovar->value,
                ])
                ->whereNotNull('fecha_termino')
                ->where('fecha_termino', '<', $hoy->toDateString())
                ->with('oferta')
                ->get();

            foreach ($operaciones as $operacion) {
                $bloqueada = Operacion::query()
                    ->whereKey($operacion->id)
                    ->lockForUpdate()
                    ->first();

                if ($bloqueada === null || ! $bloqueada->estado_contrato->estaVigente()) {
                    continue;
                }

                $oferta = $bloqueada->oferta;

                if ($oferta === null) {
                    throw new \RuntimeException(
                        'La operacion ' . $bloqueada->id . ' no tiene oferta asociada. '
                        . 'No se puede procesar su retorno.'
                    );
                }

                $bloqueada->forceFill([
                    'estado_contrato' => EstadoContrato::Retornado->value,
                ])->save();

                $this->registrador->devolverADisponible(
                    oferta: $oferta,
                    motivo: 'Retorno: el contrato de arriendo termino sin renovar',
                    usuarioId: null,
                );

                $retornados->push([
                    'operacion_id' => (string) $bloqueada->id,
                    'oferta_id' => (string) $oferta->id,
                    'dias_arrendados' => $bloqueada->fecha_inicio !== null && $bloqueada->fecha_termino !== null
                        ? max(0, (int) $bloqueada->fecha_inicio->diffInDays($bloqueada->fecha_termino))
                        : 0,
                    'renovaciones' => (int) $bloqueada->renovaciones,
                ]);
            }

            return $retornados;
        });
    }
}