<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Inmobiliaria;
use App\Services\Operaciones\LiberadorDeReservasVencidas;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Console\Command;

/**
 * Libera las reservas vencidas de TODAS las inmobiliarias.
 *
 * POR QUE RECORRE UNA POR UNA Y NO EN UNA SOLA CONSULTA
 * ----------------------------------------------------
 * Este es el comando donde el alcance de transaccion del contexto de tenant se
 * gana la vida. Si el trabajo recorriera todas las inmobiliarias con una sola
 * conexion y un `SET` de alcance de sesion, el valor de `app.tenant_id` de la
 * primera inmobiliaria sobreviviria a la iteracion y la segunda leeria con el
 * contexto equivocado: veria reservas ajenas y las liberaria.
 *
 * Con `ejecutarAislado` por inmobiliaria, cada iteracion abre su transaccion,
 * fija SU contexto, hace su trabajo y lo descarta al salir. El valor de una
 * inmobiliaria no puede sobrevivir en la iteracion siguiente.
 *
 * CUANDO CORRE
 * ------------
 * Diario, cada hora, desde el planificador de Laravel. Ver bootstrap/app.php y
 * docs/05. Corre cada hora y no una vez al dia porque una reserva puede vencer
 * a las 11 de la manana y la propiedad no tiene por que quedarse taken hasta
 * la medianoche.
 */
class LiberarReservasCommand extends Command
{
    protected $signature = 'inmobiliaria:liberar-reservas
                            {--inmobiliaria= : Limitar a una sola inmobiliaria (para depurar)}
                            {--simular : Muestra que haria, sin escribir nada}';

    protected $description = 'Libera las reservas vencidas y devuelve las propiedades al mercado';

    public function handle(LiberadorDeReservasVencidas $liberador, ContextoTenant $contexto): int
    {
        $soloUna = $this->option('inmobiliaria');
        $simular = (bool) $this->option('simular');

        // `inmobiliarias` no tiene RLS a proposito: se consulta sin contexto.
        $consulta = Inmobiliaria::query()->where('activo', true);

        if ($soloUna !== null) {
            $consulta->where('correlacion_identidad', $soloUna);
        }

        $inmobiliarias = $consulta->get();

        if ($inmobiliarias->isEmpty()) {
            $this->warn('No hay inmobiliarias activas. No hay nada que liberar.');

            return self::SUCCESS;
        }

        $total = 0;

        foreach ($inmobiliarias as $inmobiliaria) {
            // UNA transaccion por inmobiliaria. Ver la nota de la clase.
            $liberadas = $contexto->ejecutarAislado(
                (string) $inmobiliaria->id,
                function () use ($liberador, $simular, $inmobiliaria): array {
                    if ($simular) {
                        return $this->simular($inmobiliaria);
                    }

                    return $liberador->liberarPara((string) $inmobiliaria->id)->all();
                }
            );

            $total += count($liberadas);

            if ($liberadas === []) {
                $this->line('  ' . $inmobiliaria->nombre . ': sin reservas vencidas.');

                continue;
            }

            $this->info($inmobiliaria->nombre . ': ' . count($liberadas) . ' reserva(s) liberada(s).');

            foreach ($liberadas as $liberada) {
                $this->line(sprintf(
                    '    - reserva %s, vencida hace %d dia(s)',
                    mb_substr((string) $liberada['operacion_id'], 0, 8),
                    (int) $liberada['dias_vencida'],
                ));
            }
        }

        $verbo = $simular ? 'se liberarian' : 'se liberaron';

        $this->info('Total: ' . $total . ' ' . $verbo . '.');

        return self::SUCCESS;
    }

    /**
     * Que reservas vencen, sin escribir.
     *
     * La simulacion abre el mismo contexto aislado que el trabajo real: si
     * simular y ejecutar usaran caminos distintos, la simulacion no serviria
     * para anticipar el problema.
     *
     * @return array<int, array<string, mixed>>
     */
    private function simular(Inmobiliaria $inmobiliaria): array
    {
        $filas = \Illuminate\Support\Facades\DB::table('operaciones')
            ->where('tenant_id', $inmobiliaria->id)
            ->where('tipo', 'reserva')
            ->where('estado_contrato', 'vigente')
            ->whereNotNull('expira_en')
            ->where('expira_en', '<=', now())
            ->get(['id', 'expira_en']);

        return $filas->map(fn ($f): array => [
            'operacion_id' => (string) $f->id,
            'oferta_id' => '',
            'propiedad_id' => '',
            'dias_vencida' => max(0, (int) \Illuminate\Support\Carbon::parse($f->expira_en)->diffInDays(now())),
        ])->all();
    }
}