<?php

declare(strict_types=1);

namespace App\Services\Estados;

use App\Enums\EstadoComercial;
use App\Models\CambioEstado;
use App\Models\Estado;
use App\Models\Oferta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Registrador de cambios de estado: el unico que escribe en `cambios_estado`.
 *
 * ESTE SERVICIO ES EL CORAZON DEL PRODUCTO
 * ----------------------------------------
 * Si el resto de la aplicacion puede estar medio bien, este servicio es el que
 * no puede: sobre el se calcula la permanencia, que es lo unico que el cliente
 * compra. Por eso es una clase y no un metodo del modelo, y por eso las tres
 * reglas que sigue estan escritas aca y no repartidas.
 *
 * REGLA 1: NADA SE ESCRIBE A MANO
 * ------------------------------
 * Toda entrada al historial pasa por `registrar()`. Un `CambioEstado::create()`
 * suelto en un controlador produciria un tramo sin cerrar y dejaria la linea de
 * tiempo rota sin que nada lo dijera.
 *
 * REGLA 2: CERRAR ANTES DE ABRIR, EN LA MISMA TRANSACCION
 * -------------------------------------------------------
 * `registrar()` hace las tres escrituras juntas o ninguna:
 *
 *     1. cierra el tramo abierto (pone `hasta` y `duracion_dias`);
 *     2. abre el tramo nuevo con `hasta` en NULL;
 *     3. actualiza la copia desnormalizada de `ofertas`.
 *
 * La regla 3 es lo que obliga a que 1 y 2 sean atomicos: si se hiciera el
 * UPDATE de la oferta y fallara el INSERT del tramo, la propiedad quedaria
 * mostrando un estado que no tiene historia. La prueba
 * `CoherenciaDelHistorialTest` existe para que ese estado no pueda volver.
 *
 * REGLA 3: MIENTRAS MIDA, NO SE TOCA
 * ----------------------------------
 * El `desde` de un tramo ya cerrado esta protegido por un disparador de
 * PostgreSQL (migracion 2026_02_10_000006). Esta clase no intenta saltarselo:
 * si la historia hay que corregirla, se corrige con un cambio de estado nuevo y
 * un motivo, que es lo que un informe puede defender despues.
 */
class RegistradorDeCambioEstado
{
    /**
     * Arranca el historial de una oferta recien creada.
     *
     * Separate de `registrar()` porque no hay estado anterior: este es el
     * primer tramo, y por eso va con `estado_anterior_id` en NULL.
     *
     * Por que existe y no se hace con un `create()` en el controlador: si el
     * alta de la oferta y la del primer tramo se hicieran por separado, una
     * podria quedar escrita y la otra no, y el resultado seria una oferta
     * "viva" sin historia, que es exactamente el estado que el producto no
     * puede tener.
     */
    public function registrarPrimerTramo(Oferta $oferta, Estado $estadoInicial): CambioEstado
    {
        if ($oferta->cambiosEstado()->exists()) {
            throw new RuntimeException(
                'La oferta ' . $oferta->id . ' ya tiene historial. No se puede arrancar dos veces.'
            );
        }

        return DB::transaction(function () use ($oferta, $estadoInicial): CambioEstado {
            $tramo = CambioEstado::query()->create([
                'tenant_id' => $oferta->tenant_id,
                'oferta_id' => $oferta->id,
                'estado_anterior_id' => null,
                'estado_nuevo_id' => $estadoInicial->id,
                'desde' => $oferta->estado_desde ?? Carbon::now(),
                'hasta' => null,
                'duracion_dias' => null,
                'usuario_id' => null,
                'motivo' => 'Alta de la oferta',
            ]);

            // Se sincroniza la copia desnormalizada con el instante del tramo,
            // para que las dos digan exactamente lo mismo.
            $oferta->forceFill([
                'estado_id' => $estadoInicial->id,
                'estado_desde' => $tramo->desde,
            ])->save();

            return $tramo;
        });
    }

    /**
     * Registra el paso de una oferta a un estado nuevo y devuelve el tramo
     * abierto.
     *
     * @param  string|null  $usuarioId  NULL solo para cambios automaticos
     *                                   (liberacion de reserva vencida).
     * @throws RuntimeException si se intenta cerrar un tramo que no existe, o
     *                          cambiar al mismo estado en que ya se esta.
     */
    public function registrar(
        Oferta $oferta,
        Estado $estadoNuevo,
        string $motivo,
        ?string $usuarioId = null,
    ): CambioEstado {
        // El estado anterior se lee de la oferta, que es la copia
        // desnormalizada. No se vuelve a preguntar a `cambios_estado`: si las
        // dos copias divergieran, esta clase elegiria una y la otra quedaria
        // mintiendo en silencio. La divergencia se detecta en la prueba, no se
        // resuelve aqui.
        $estadoAnteriorId = $oferta->estado_id;

        if ($estadoAnteriorId === $estadoNuevo->id) {
            throw new RuntimeException(
                'La oferta ya esta en el estado ' . $estadoNuevo->nombre . '. '
                . 'Registrar el mismo estado dos veces no produce historial, produce ruido.'
            );
        }

        return DB::transaction(function () use ($oferta, $estadoNuevo, $motivo, $usuarioId, $estadoAnteriorId): CambioEstado {
            $ahora = Carbon::now();

            $this->cerrarTramoAbierto($oferta, $ahora);

            $tramo = CambioEstado::query()->create([
                'tenant_id' => $oferta->tenant_id,
                'oferta_id' => $oferta->id,
                'estado_anterior_id' => $estadoAnteriorId,
                'estado_nuevo_id' => $estadoNuevo->id,
                'desde' => $ahora,
                'hasta' => null,
                'duracion_dias' => null,
                'usuario_id' => $usuarioId,
                'motivo' => $motivo,
            ]);

            // La copia desnormalizada, en la misma transaccion. Ver la
            // justificacion en la migracion de `ofertas`.
            $oferta->forceFill([
                'estado_id' => $estadoNuevo->id,
                'estado_desde' => $ahora,
            ])->save();

            return $tramo;
        });
    }

    /**
     * Cierra el tramo abierto de la oferta, si hay uno.
     *
     * Se permite que no haya ninguno: es el caso de la oferta recien creada,
     * cuyo primer tramo tiene `estado_anterior_id` NULL.
     */
    private function cerrarTramoAbierto(Oferta $oferta, Carbon $ahora): void
    {
        $abierto = CambioEstado::query()
            ->where('oferta_id', $oferta->id)
            ->whereNull('hasta')
            ->orderByDesc('desde')
            ->first();

        if ($abierto === null) {
            return;
        }

        // La duracion se mide aqui, una vez, con el reloj ya resuelto. El
        // informe la vuelve a leer, pero no la recalcula: por eso
        // `duracion_dias` esta guardado y no derivado.
        $abierto->forceFill([
            'hasta' => $ahora,
            'duracion_dias' => $this->diasTranscurridos($abierto->desde, $ahora),
        ])->save();
    }

    /**
     * Dias entre dos instantes.
     *
     * Redondea hacia abajo a proposito: "llevo 1 dia y 23 horas" se informa como
     * 1 dia. Un informe de permanencia que sobrestima el tiempo envejece mal la
     * practica, y se ve en el promedio.
     */
    private function diasTranscurridos(Carbon $desde, Carbon $hasta): int
    {
        return max(0, (int) $desde->diffInDays($hasta));
    }

    /**
     * Devuelve la oferta al estado disponible, que es lo que hace falta cuando
     * se cae una reserva o termina un arriendo.
     *
     * Se resuelve el estado por su `clave_sistema`, no por su nombre: la
     * inmobiliaria puede llamarlo "en oferta" y el retorno tiene que funcionar
     * igual. Si la inmobiliaria no tiene un estado con esa clave, es un error de
     * configuracion y se dice, en vez de dejar la propiedad en un limbo.
     */
    public function devolverADisponible(Oferta $oferta, string $motivo, ?string $usuarioId = null): CambioEstado
    {
        $disponible = Estado::query()
            ->where('tenant_id', $oferta->tenant_id)
            ->where('clave_sistema', EstadoComercial::Disponible->value)
            ->first();

        if ($disponible === null) {
            throw new RuntimeException(
                'La inmobiliaria ' . $oferta->tenant_id . ' no tiene un estado con la clave de sistema '
                . EstadoComercial::Disponible->value . '. Sin ese estado no se puede devolver una '
                . 'propiedad al mercado. Corrija la configuracion de estados.'
            );
        }

        return $this->registrar($oferta, $disponible, $motivo, $usuarioId);
    }
}