<?php

declare(strict_types=1);

namespace App\Services\Operaciones;

use App\Enums\EstadoContrato;
use App\Models\Operacion;
use App\Models\Oferta;
use App\Services\Estados\RegistradorDeCambioEstado;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Liberador de reservas vencidas: la regla que evita perder ventas por inercia.
 *
 * EL PROBLEMA QUE RESUELVE
 * ------------------------
 * "La reserva tiene fecha de expiracion. Si nadie confirma antes de esa fecha,
 * la propiedad vuelve al mercado. Si el sistema no la libera, la inmobiliaria
 * pierde ventas por inercia."
 *
 * Perder por inercia no es un error visible: la propiedad queda reservada, el
 * caso no aparece en ningun reporte de error, y la venta que se perdio se
 * perdio hacia otro. Por eso la liberacion es una TAREA AUTOMATICA y no una
 * buena practica.
 *
 * QUE HACE, EN ORDEN
 * ------------------
 * Por cada reserva vencida y vigente:
 *
 *     1. Marca la operacion como `expirada`.
 *     2. Devuelve la oferta al estado disponible, anotando un cambio de estado
 *        AUTOMATICO (sin usuario).
 *
 * Las dos cosas van en la misma transaccion que la lectura, y la oferta se
 * bloquea con `lockForUpdate`. Sin el bloqueo, dos workers corriendo a la vez
 * liberarian la misma reserva dos veces, y la linea de tiempo tendria dos
 * cambios automaticos con el mismo instante.
 *
 * POR QUE ES POR INMOBILIARIA Y NO UN TRABAJO GLOBAL
 * ---------------------------------------------------
 * Un trabajo global que recorre todas las reservas con una sola conexion es
 * exactamente el escenario que el alcance de transaccion del contexto de tenant
 * evita: si el valor de `app.tenant_id` sobreviviera entre iteraciones, la
 * segunda inmobiliaria leeria con el contexto de la primera y veria reservas
 * ajenas. Por eso `liberarPara` exige la inmobiliaria y abre una transaccion
 * por cada una. Ver docs/03.
 */
class LiberadorDeReservasVencidas
{
    public function __construct(private readonly RegistradorDeCambioEstado $registrador) {}

    /**
     * Libera las reservas vencidas de UNA inmobiliaria.
     *
     * @return Collection<int, array{operacion_id: string, oferta_id: string, propiedad_id: string, dias_vencida: int}>
     */
    public function liberarPara(string $tenantId, ?Carbon $ahora = null): Collection
    {
        $ahora ??= Carbon::now();

        return DB::transaction(function () use ($tenantId, $ahora): Collection {
            $liberadas = collect();

            $operaciones = Operacion::query()
                ->where('tenant_id', $tenantId)
                ->where('tipo', 'reserva')
                ->where('estado_contrato', EstadoContrato::Vigente->value)
                ->whereNotNull('expira_en')
                ->where('expira_en', '<=', $ahora)
                ->with('oferta')
                ->get();

            foreach ($operaciones as $operacion) {
                // El bloqueo va aqui y no en la consulta de arriba a proposito:
                // hay que bloquear la fila recien leida y que nadie mas la
                // haya tocado entre la lectura y el UPDATE.
                $bloqueada = Operacion::query()
                    ->whereKey($operacion->id)
                    ->lockForUpdate()
                    ->first();

                if ($bloqueada === null || ! $bloqueada->estado_contrato->estaVigente()) {
                    continue;
                }

                $oferta = $bloqueada->oferta;

                if ($oferta === null) {
                    // No puede pasar por la FK, pero un dato inconsistente
                    // aca dejaria la propiedad tomada para siempre. Se dice.
                    throw new \RuntimeException(
                        'La operacion ' . $bloqueada->id . ' no tiene oferta asociada. '
                        . 'No se puede liberar la reserva.'
                    );
                }

                $bloqueada->forceFill([
                    'estado_contrato' => EstadoContrato::Expirada->value,
                ])->save();

                $this->registrador->devolverADisponible(
                    oferta: $oferta,
                    // Sin usuario a proposito: en la linea de tiempo queda
                    // como cambio del sistema, no como decision de un corredor.
                    // La interfaz muestra "liberada por vencimiento", y no un
                    // nombre al que culpar.
                    motivo: 'Liberacion automatica de reserva vencida',
                    usuarioId: null,
                );

                $liberadas->push([
                    'operacion_id' => (string) $bloqueada->id,
                    'oferta_id' => (string) $oferta->id,
                    'propiedad_id' => (string) $oferta->propiedad_id,
                    'dias_vencida' => max(0, (int) $bloqueada->expira_en->diffInDays($ahora)),
                ]);
            }

            return $liberadas;
        });
    }
}