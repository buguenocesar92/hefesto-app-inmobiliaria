<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Inmobiliaria;
use App\Services\Operaciones\ProcesadorDeRetornos;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Console\Command;

/**
 * Marca como retornados los arriendos que terminaron sin renovar.
 *
 * "Un arriendo que termina y no se renueva es un retorno, no un evento neutro."
 *
 * La diferencia con una baja cualquiera es que el retorno DEJA MEDIRSE: la
 * propiedad vuelve al mercado y el producto puede decir cuanto tardo en
 * arrendar de nuevo. Por eso el retorno escribe un cambio de estado en el
 * historial, igual que la liberacion de una reserva.
 *
 * Corre diario. Ver la nota de alcance de transaccion en LiberarReservasCommand:
 * el mismo criterio aplica, una transaccion por inmobiliaria.
 */
class MarcarRetornosCommand extends Command
{
    protected $signature = 'inmobiliaria:marcar-retornos';

    protected $description = 'Marca como retornados los arriendos terminados sin renovar';

    public function handle(ProcesadorDeRetornos $procesador, ContextoTenant $contexto): int
    {
        $inmobiliarias = Inmobiliaria::query()->where('activo', true)->get();

        $total = 0;

        foreach ($inmobiliarias as $inmobiliaria) {
            $retornados = $contexto->ejecutarAislado(
                (string) $inmobiliaria->id,
                fn (): array => $procesador->procesarPara((string) $inmobiliaria->id)->all()
            );

            $total += count($retornados);

            if ($retornados === []) {
                $this->line('  ' . $inmobiliaria->nombre . ': sin retornos pendientes.');

                continue;
            }

            $this->info($inmobiliaria->nombre . ': ' . count($retornados) . ' retorno(s).');

            foreach ($retornados as $retorno) {
                $this->line(sprintf(
                    '    - arriendo %s: %d dia(s) arrendado(s), %d renovacion(es)',
                    mb_substr((string) $retorno['operacion_id'], 0, 8),
                    (int) $retorno['dias_arrendados'],
                    (int) $retorno['renovaciones'],
                ));
            }
        }

        $this->info('Total: ' . $total . ' retorno(s).');

        return self::SUCCESS;
    }
}