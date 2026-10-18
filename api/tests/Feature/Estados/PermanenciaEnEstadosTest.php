<?php

declare(strict_types=1);

namespace Tests\Feature\Estados;

use App\Enums\EstadoComercial;
use App\Enums\TipoOperacion;
use App\Models\CambioEstado;
use App\Models\Estado;
use App\Models\Oferta;
use App\Services\Estados\MedidorDePermanencia;
use App\Services\Estados\RegistradorDeCambioEstado;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El historial de estados: que se escriba bien y que mida bien.
 *
 * ESTA ES LA PRUEBA DEL PRODUCTO
 * ------------------------------
 * Las otras prueban que los datos no se mezclen. Esta comprueba que el numero
 * que el cliente compra sea el numero correcto.
 *
 * Y hay un caso que se prueba a proposito porque es el que hace util el producto:
 * una sola reserva que duro 400 dias. El PROMEDIO de ese conjunto es de 82 dias,
 * que no describe a nadie. La MEDIANA y el p90 si lo describen, y por eso se
 * calculan los tres.
 */
class PermanenciaEnEstadosTest extends TestCase
{
    use RefreshDatabase;

    public function test_el_primer_tramo_de_una_oferta_no_tiene_estado_anterior(): void
    {
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $oferta = $this->crearOfertaDeVenta($inmobiliaria);

        $tramos = $this->dentroDe($inmobiliaria, fn (): \Illuminate\Support\Collection => CambioEstado::query()
            ->where('oferta_id', $oferta->id)
            ->get());

        $this->assertCount(1, $tramos, 'La oferta deberia arrancar con un tramo.');
        $this->assertNull($tramos->first()->estado_anterior_id);
        $this->assertTrue($tramos->first()->estaAbierto());
    }

    public function test_cambiar_de_estado_cierra_el_tramo_anterior_y_abre_uno_nuevo(): void
    {
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $oferta = $this->crearOfertaDeVenta($inmobiliaria);

        $reservada = $this->dentroDe($inmobiliaria, fn (): Estado => Estado::query()
            ->where('clave_sistema', EstadoComercial::Reservada->value)
            ->firstOrFail());

        $this->dentroDe($inmobiliaria, function () use ($oferta, $reservada): void {
            app(RegistradorDeCambioEstado::class)->registrar(
                oferta: $oferta->refresh(),
                estadoNuevo: $reservada,
                motivo: 'Reserva tomada',
                usuarioId: null,
            );
        });

        $tramos = $this->dentroDe($inmobiliaria, fn (): \Illuminate\Support\Collection => CambioEstado::query()
            ->where('oferta_id', $oferta->id)
            ->orderBy('desde')
            ->get());

        $this->assertCount(2, $tramos);

        $primero = $tramos[0];
        $segundo = $tramos[1];

        $this->assertFalse($primero->estaAbierto(), 'El primer tramo deberia haberse cerrado.');
        $this->assertNotNull($primero->duracion_dias);
        $this->assertTrue($segundo->estaAbierto(), 'Solo el ultimo tramo queda abierto.');

        $this->assertSame($primero->estado_nuevo_id, $segundo->estado_anterior_id);
    }

    public function test_solo_hay_un_tramo_abierto_por_oferta(): void
    {
        // Lo garantiza el indice unico parcial `uq_cambios_oferta_tramo_abierto`.
        // Si no lo hubiera, "el estado actual" seria ambiguo y el tablero
        // contaria la propiedad dos veces.
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $oferta = $this->crearOfertaDeVenta($inmobiliaria);

        $reservada = $this->dentroDe($inmobiliaria, fn (): Estado => Estado::query()
            ->where('clave_sistema', EstadoComercial::Reservada->value)
            ->firstOrFail());

        $disponible = $this->dentroDe($inmobiliaria, fn (): Estado => Estado::query()
            ->where('clave_sistema', EstadoComercial::Disponible->value)
            ->firstOrFail());

        // Dos registros abiertos a mano, saltandose el servicio.
        $this->dentroDe($inmobiliaria, function () use ($oferta, $reservada, $disponible): void {
            // El primero falla, y eso es lo que se comprueba.
            try {
                CambioEstado::query()->create([
                    'tenant_id' => $oferta->tenant_id,
                    'oferta_id' => $oferta->id,
                    'estado_nuevo_id' => $reservada->id,
                    'desde' => now()->subDays(5),
                    'hasta' => null,
                    'duracion_dias' => null,
                    'motivo' => 'Intento de tramo abierto duplicado',
                ]);

                $this->fail('Deberia haberse rechazado un segundo tramo abierto.');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('uq_cambios_oferta_tramo_abierto', $e->getMessage());
            }

            $this->assertTrue(true);
        });
    }

    public function test_la_permanencia_devuelve_promedio_mediana_y_p90(): void
    {
        // El caso que da sentido al producto: cinco reservas rapidas y una
        // eterna. El promedio miente; la mediana y el p90 no.
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');

        $duraciones = [10, 12, 9, 11, 13, 400];

        foreach ($duraciones as $duracion) {
            $oferta = $this->crearOfertaDeVenta($inmobiliaria);

            $this->registrarReservaQueDuro($inmobiliaria, $oferta, $duracion);
        }

        $filas = $this->dentroDe($inmobiliaria, fn () => app(MedidorDePermanencia::class)
            ->porEstado((string) $inmobiliaria->id, TipoOperacion::Venta));

        $reservada = $filas->firstWhere('nombre', 'Reservada');

        $this->assertNotNull($reservada, 'Deberia existir la fila del estado reservada.');
        $this->assertSame(6, $reservada['tramos_cerrados']);

        // Promedio: (10+12+9+11+13+400)/6 = 75,8
        $this->assertEqualsWithDelta(75.8, $reservada['promedio_dias'], 0.1);

        // Mediana: entre 12 y 13 -> 12,5. Un promedio solo habria dado 75,8, que
        // no describe a ninguna reserva real.
        $this->assertEqualsWithDelta(12.5, $reservada['mediana_dias'], 0.1);

        // P90: 400 es el maximo, y con seis muestras el p90 cae en el mas alto.
        $this->assertEqualsWithDelta(400.0, $reservada['p90_dias'], 0.1);

        $this->assertSame(400, $reservada['maximo_dias']);
    }

    public function test_un_estado_sin_historial_aparece_con_ceros_y_no_con_nulos(): void
    {
        // Si el tablero escondiera un estado sin historial, el usuario creeria
        // que el estado no existe.
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');

        $filas = $this->dentroDe($inmobiliaria, fn () => app(MedidorDePermanencia::class)
            ->porEstado((string) $inmobiliaria->id, TipoOperacion::Venta));

        $escriturado = $filas->firstWhere('nombre', 'Escriturado');

        $this->assertNotNull($escriturado, 'El estado escriturado deberia aparecer igual sin historial.');
        $this->assertSame(0, $escriturado['tramos_cerrados']);
        $this->assertSame(0, $escriturado['en_curso']);
        $this->assertSame(0, $escriturado['promedio_dias']);
    }

    public function test_las_propiedades_en_curso_vienen_de_la_mas_antigua(): void
    {
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');

        // Tres ofertas con antiguedades distintas.
        $ofertas = [];

        foreach ([200, 50, 10] as $dias) {
            $oferta = $this->crearOfertaDeVenta($inmobiliaria, null, [
                'direccion' => 'Direccion ' . $dias,
            ]);

            $this->moverAEstradoConAntiguedad($inmobiliaria, $oferta, $dias);

            $ofertas[$dias] = $oferta;
        }

        $filas = $this->dentroDe($inmobiliaria, fn () => app(MedidorDePermanencia::class)
            ->propiedadesEnCurso((string) $inmobiliaria->id, TipoOperacion::Venta));

        $this->assertCount(3, $filas);

        // El orden ES el diagnostico: lo mas antiguo primero.
        $this->assertSame(200, $filas[0]['dias_en_estado']);
        $this->assertSame(50, $filas[1]['dias_en_estado']);
        $this->assertSame(10, $filas[2]['dias_en_estado']);
    }

    public function test_la_copia_del_estado_en_la_oferta_no_diverge_del_historial(): void
    {
        // `ofertas.estado_id` es una COPIA de lo que dice el ultimo tramo. Si
        // divergieran, el tablero mostraria un estado que no tiene historia.
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $oferta = $this->crearOfertaDeVenta($inmobiliaria);

        $reservada = $this->dentroDe($inmobiliaria, fn (): Estado => Estado::query()
            ->where('clave_sistema', EstadoComercial::Reservada->value)
            ->firstOrFail());

        $this->dentroDe($inmobiliaria, function () use ($oferta, $reservada): void {
            app(RegistradorDeCambioEstado::class)->registrar(
                oferta: $oferta->refresh(),
                estadoNuevo: $reservada,
                motivo: 'Reserva tomada',
                usuarioId: null,
            );
        });

        $oferta->refresh();

        $ultimoTramo = $this->dentroDe($inmobiliaria, fn (): CambioEstado => CambioEstado::query()
            ->where('oferta_id', $oferta->id)
            ->whereNull('hasta')
            ->firstOrFail());

        $this->assertSame(
            $ultimoTramo->estado_nuevo_id,
            $oferta->estado_id,
            'La copia del estado en la oferta no coincide con el ultimo tramo.'
        );

        $this->assertEquals(
            $ultimoTramo->desde->timestamp,
            $oferta->estado_desde->timestamp(),
            'La fecha de la copia no coincide con la del ultimo tramo.'
        );
    }

    public function test_registrar_el_mismo_estado_dos_veces_falla(): void
    {
        // No produce historial, produce ruido, y ademas dejaria dos tramos con el
        // mismo estado que inflan el conteo.
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $oferta = $this->crearOfertaDeVenta($inmobiliaria);

        $disponible = $this->dentroDe($inmobiliaria, fn (): Estado => Estado::query()
            ->where('clave_sistema', EstadoComercial::Disponible->value)
            ->firstOrFail());

        $this->expectException(\RuntimeException::class);

        $this->dentroDe($inmobiliaria, fn () => app(RegistradorDeCambioEstado::class)->registrar(
            oferta: $oferta,
            estadoNuevo: $disponible,
            motivo: 'Repeticion',
            usuarioId: null,
        ));
    }

    /**
     * Deja una oferta con un tramo RESERVADA cerrado de exactamente
     * `$duracionDias` dias, y devuelta a disponible.
     *
     * EL ORDEN IMPORTA
     * ----------------
     * La duracion la calcula el servicio al CERRAR el tramo, restando `desde`
     * del instante de cierre. Para que el tramo mida exactamente N dias, hay
     * que correrle el `desde` a N dias atras MIENTRAS ESTA ABIERTO, y cerrarlo
     * despues.
     *
     * Correr un tramo ya cerrado esta prohibido por el disparador
     * `trg_cambios_estado_no_reescribir`, y eso es justamente lo que protege la
     * medida: un tramo cerrado no se puede reescribir despues para que el
     * promedio salga mejor.
     */
    private function registrarReservaQueDuro(
        \App\Models\Inmobiliaria $inmobiliaria,
        Oferta $oferta,
        int $duracionDias,
    ): void {
        $reservada = $this->dentroDe($inmobiliaria, fn (): Estado => Estado::query()
            ->where('clave_sistema', EstadoComercial::Reservada->value)
            ->firstOrFail());

        $disponible = $this->dentroDe($inmobiliaria, fn (): Estado => Estado::query()
            ->where('clave_sistema', EstadoComercial::Disponible->value)
            ->firstOrFail());

        $this->dentroDe($inmobiliaria, function () use ($oferta, $reservada, $disponible, $duracionDias): void {
            $registrador = app(RegistradorDeCambioEstado::class);

            // 1. Abrir el tramo en reservada.
            $registrador->registrar(
                oferta: $oferta->refresh(),
                estadoNuevo: $reservada,
                motivo: 'Reserva tomada',
                usuarioId: null,
            );

            // 2. Correr su inicio, todavia abierto.
            CambioEstado::query()
                ->where('oferta_id', $oferta->id)
                ->whereNull('hasta')
                ->update(['desde' => Carbon::now()->subDays($duracionDias)]);

            // 3. Cerrarlo: el servicio mide la duracion y la guarda.
            $registrador->registrar(
                oferta: $oferta->refresh(),
                estadoNuevo: $disponible,
                motivo: 'Reserva liberada',
                usuarioId: null,
            );
        });
    }

    /**
     * Deja la oferta en un estado con una antiguedad dada, sin abrir el
     * historial de mas.
     */
    private function moverAEstradoConAntiguedad(
        \App\Models\Inmobiliaria $inmobiliaria,
        Oferta $oferta,
        int $dias,
    ): void {
        $reservada = $this->dentroDe($inmobiliaria, fn (): Estado => Estado::query()
            ->where('clave_sistema', EstadoComercial::Reservada->value)
            ->firstOrFail());

        $this->dentroDe($inmobiliaria, function () use ($oferta, $reservada, $dias): void {
            app(RegistradorDeCambioEstado::class)->registrar(
                oferta: $oferta->refresh(),
                estadoNuevo: $reservada,
                motivo: 'Reserva tomada',
                usuarioId: null,
            );

            $oferta->refresh()->forceFill([
                'estado_desde' => Carbon::now()->subDays($dias),
            ])->save();

            CambioEstado::query()
                ->where('oferta_id', $oferta->id)
                ->whereNull('hasta')
                ->update(['desde' => Carbon::now()->subDays($dias)]);
        });
    }
}