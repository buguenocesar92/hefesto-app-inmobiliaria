<?php

declare(strict_types=1);

use App\Models\Inmobiliaria;
use App\Services\Operaciones\LiberadorDeReservasVencidas;
use App\Services\Operaciones\ProcesadorDeRetornos;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Support\Facades\Schedule;

/*
|------------------------------------------------------------------------------
| Planificador
|------------------------------------------------------------------------------
| Las reglas automaticas del negocio. Ninguna depende de que alguien se acuerde.
|
| QUE HAY Y CADA CUANTO
| ---------------------
|   - Liberacion de reservas vencidas: cada hora.
|   - Retorno de arriendos terminados: diario, de madrugada.
|
| POR QUE CADA HORA Y NO UNA VEZ AL DIA
| --------------------------------------
| Una reserva puede vencer a las once de la manana. Si el trabajo corriera a la
| medianoche, la propiedad quedaria tomada hasta las 24 horas siguientes: doce
| horas de inercia, que es justo lo que este producto existe para evitar.
|
| POR QUE UNA TRANSACCION POR INMOBILIARIA
| ---------------------------------------
| El trabajo recorre TODAS las inmobiliarias en la MISMA conexion. Si el
| contexto de tenant tuviera alcance de sesion en vez de transaccion, la segunda
| iteracion leeria con el contexto de la primera y liberaria reservas ajenas.
|
| Por eso el bucle de abajo abre y cierra una transaccion POR INMOBILIARIA, y
| nunca envuelve todo el recorrido en una sola. Ver la seccion 3.3 de docs/03.
|
*/

/**
 * Recorre las inmobiliarias activas abriendo una transaccion aislada para cada
 * una, y le entrega su id.
 *
 * El cierre va POR ITERACION a proposito: el contexto de una tiene que estar
 * descartado antes de que se abra la siguiente.
 *
 * @param  Closure(string): void  $porInmobiliaria
 */
$recorrer = function (Closure $porInmobiliaria): void {
    $contexto = app(ContextoTenant::class);

    // `inmobiliarias` no tiene RLS: se puede leer sin contexto, que es
    // justamente lo que hace falta para saber a quienes hay que visitar.
    Inmobiliaria::query()
        ->where('activo', true)
        ->get()
        ->each(function (Inmobiliaria $inmobiliaria) use ($contexto, $porInmobiliaria): void {
            $contexto->ejecutarAislado(
                (string) $inmobiliaria->id,
                fn () => $porInmobiliaria((string) $inmobiliaria->id)
            );
        });
};

Schedule::call(function () use ($recorrer): void {
    $liberador = app(LiberadorDeReservasVencidas::class);

    $recorrer(fn (string $tenantId) => $liberador->liberarPara($tenantId));
})
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Libera las reservas vencidas y devuelve las propiedades al mercado');

Schedule::call(function () use ($recorrer): void {
    $procesador = app(ProcesadorDeRetornos::class);

    $recorrer(fn (string $tenantId) => $procesador->procesarPara($tenantId));
})
    ->dailyAt('03:10')
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Marca como retornados los arriendos terminados sin renovar');