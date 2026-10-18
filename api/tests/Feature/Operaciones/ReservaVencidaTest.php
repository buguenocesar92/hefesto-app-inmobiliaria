<?php

declare(strict_types=1);

namespace Tests\Feature\Operaciones;

use App\Enums\EstadoComercial;
use App\Enums\EstadoContrato;
use App\Models\CambioEstado;
use App\Models\Operacion;
use App\Models\Oferta;
use App\Services\Operaciones\LiberadorDeReservasVencidas;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La reserva vencida se libera sola y la propiedad vuelve al mercado.
 *
 * "Si el sistema no la libera, la inmobiliaria pierde ventas por inercia."
 *
 * ESTA ES LA REGLA AUTOMATICA MAS IMPORTANTE DEL SISTEMA
 * -----------------------------------------------------
 * Y por eso la prueba mira las tres cosas que tienen que pasar, no solo una:
 *
 *   1. La operacion pasa a `expirada`.
 *   2. La oferta vuelve al estado disponible.
 *   3. Queda un cambio de estado AUTOMATICO en el historial, con su duracion.
 *
 * La tercera es la que casi siempre se olvida, y es la que hace que el producto
 * sirva: si la liberacion no se anotara en el historial, el tiempo que la
 * propiedad estuvo reservada no existiria para nadie, y el tablero mostraria un
 * tiempo de permanencia mas corto que el real.
 */
class ReservaVencidaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @test
     */
    public function test_una_reserva_vencida_se_libera_sola_y_devuelve_la_propiedad_al_mercado(): void
    {
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $corredor = $this->crearUsuario($inmobiliaria, 'corredor', 'Corredor Uno');
        $cliente = $this->crearCliente($inmobiliaria, $corredor, 'Comprador');

        $oferta = $this->crearOfertaDeVenta($inmobiliaria, $corredor);

        // La reserva: pactada en 118 millones (por DEBAJO de los 120 de lista,
        // que es lo normal) y venciendo hace cinco dias.
        $reserva = $this->crearReserva(
            $inmobiliaria,
            $oferta,
            $cliente,
            $corredor,
            expiraEn: Carbon::now()->subDays(5),
            valorPactado: 118_000_000,
        );

        $liberadas = $this->dentroDe($inmobiliaria, fn () => app(LiberadorDeReservasVencidas::class)
            ->liberarPara((string) $inmobiliaria->id));

        // 1. La operacion quedo expirada.
        $liberadas->assertCount(1);
        $reserva->refresh();
        $this->assertSame(EstadoContrato::Expirada, $reserva->estado_contrato);

        // 2. La oferta volvió al estado disponible.
        $oferta->refresh();
        $disponible = $this->dentroDe($inmobiliaria, fn () => \App\Models\Estado::query()
            ->where('clave_sistema', EstadoComercial::Disponible->value)
            ->firstOrFail());

        $this->assertSame(
            $disponible->id,
            $oferta->estado_id,
            'La oferta deberia haber vuelto al estado disponible.'
        );

        // 3. Y quedo anotado como cambio automatico, con duracion.
        $ultimoTramo = $this->dentroDe($inmobiliaria, fn (): CambioEstado => CambioEstado::query()
            ->where('oferta_id', $oferta->id)
            ->orderByDesc('desde')
            ->firstOrFail());

        $this->assertTrue($ultimoTramo->estaAbierto(), 'El ultimo tramo deberia quedar abierto.');
        $this->assertTrue(
            $ultimoTramo->esAutomatico(),
            'Una liberacion por vencimiento la hace el sistema, no un corredor.'
        );
        $this->assertNull($ultimoTramo->usuario_id);
        $this->assertStringContainsString('vencida', (string) $ultimoTramo->motivo);
    }

    /**
     * @test
     */
    public function test_el_tramo_reservada_queda_cerrado_con_su_duracion(): void
    {
        // El tiempo en reservado es el numero que el producto vende. Si al
        // liberar la reserva el tramo de "reservada" no quedara cerrado y
        // medido, ese numero se perderia.
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $corredor = $this->crearUsuario($inmobiliaria, 'corredor', 'Corredor Uno');
        $cliente = $this->crearCliente($inmobiliaria, $corredor, 'Comprador');

        $oferta = $this->crearOfertaDeVenta($inmobiliaria, $corredor);

        $this->crearReserva(
            $inmobiliaria,
            $oferta,
            $cliente,
            $corredor,
            expiraEn: Carbon::now()->subDays(5),
            valorPactado: 118_000_000,
        );

        // La oferta estaba en reservada.
        $oferta->refresh();
        $this->assertNotSame(
            $this->dentroDe($inmobiliaria, fn () => \App\Models\Estado::query()
                ->where('clave_sistema', EstadoComercial::Disponible->value)->firstOrFail())->id,
            $oferta->estado_id,
            'La oferta deberia estar reservada antes de liberar.'
        );

        $this->dentroDe($inmobiliaria, fn () => app(LiberadorDeReservasVencidas::class)
            ->liberarPara((string) $inmobiliaria->id));

        $oferta->refresh();

        $tramos = $this->dentroDe($inmobiliaria, fn (): \Illuminate\Support\Collection => CambioEstado::query()
            ->where('oferta_id', $oferta->id)
            ->orderBy('desde')
            ->get());

        $this->assertCount(2, $tramos, 'Debe haber un tramo inicial y uno de liberacion.');

        $tramoReservada = $tramos->first();

        $this->assertFalse(
            $tramoReservada->estaAbierto(),
            'El tramo de reservada deberia quedar cerrado al liberar.'
        );

        $this->assertNotNull(
            $tramoReservada->duracion_dias,
            'Un tramo cerrado tiene que traer su duracion.'
        );
    }

    /**
     * @test
     */
    public function test_una_reserva_que_no_vencio_no_se_toca(): void
    {
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $corredor = $this->crearUsuario($inmobiliaria, 'corredor', 'Corredor Uno');
        $cliente = $this->crearCliente($inmobiliaria, $corredor, 'Comprador');

        $oferta = $this->crearOfertaDeVenta($inmobiliaria, $corredor);

        $reserva = $this->crearReserva(
            $inmobiliaria,
            $oferta,
            $cliente,
            $corredor,
            expiraEn: Carbon::now()->addDays(10),
            valorPactado: 118_000_000,
        );

        $liberadas = $this->dentroDe($inmobiliaria, fn () => app(LiberadorDeReservasVencidas::class)
            ->liberarPara((string) $inmobiliaria->id));

        $liberadas->assertCount(0, 'Una reserva vigente no se libera.');

        $reserva->refresh();
        $this->assertSame(EstadoContrato::Vigente, $reserva->estado_contrato);
    }

    /**
     * @test
     */
    public function test_no_se_libera_dos_veces_la_misma_reserva(): void
    {
        // Sin esto, dos workers corriendo a la vez anotarian dos liberaciones
        // y el tramo reservado se partiria en dos.
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $corredor = $this->crearUsuario($inmobiliaria, 'corredor', 'Corredor Uno');
        $cliente = $this->crearCliente($inmobiliaria, $corredor, 'Comprador');

        $oferta = $this->crearOfertaDeVenta($inmobiliaria, $corredor);

        $this->crearReserva(
            $inmobiliaria,
            $oferta,
            $cliente,
            $corredor,
            expiraEn: Carbon::now()->subDays(1),
            valorPactado: 118_000_000,
        );

        $primera = $this->dentroDe($inmobiliaria, fn () => app(LiberadorDeReservasVencidas::class)
            ->liberarPara((string) $inmobiliaria->id));

        $segunda = $this->dentroDe($inmobiliaria, fn () => app(LiberadorDeReservasVencidas::class)
            ->liberarPara((string) $inmobiliaria->id));

        $primera->assertCount(1);
        $segunda->assertCount(0, 'La segunda pasada no deberia liberar nada.');

        $cambios = $this->dentroDe($inmobiliaria, fn (): int => CambioEstado::query()
            ->where('oferta_id', $oferta->id)
            ->count());

        $this->assertSame(2, $cambios, 'Solo puede haber un tramo de liberacion.');
    }

    /**
     * @test
     */
    public function test_la_liberacion_no_toca_reservas_de_otra_inmobiliaria(): void
    {
        $a = $this->crearInmobiliaria('Inmobiliaria A');
        $b = $this->crearInmobiliaria('Inmobiliaria B');

        $corredorB = $this->crearUsuario($b, 'corredor', 'Corredor de B');
        $clienteB = $this->crearCliente($b, $corredorB, 'Comprador B');

        $ofertaB = $this->crearOfertaDeVenta($b, $corredorB);

        $this->crearReserva(
            $b,
            $ofertaB,
            $clienteB,
            $corredorB,
            expiraEn: Carbon::now()->subDays(3),
            valorPactado: 90_000_000,
        );

        // Se corre el liberador SOLO para A. La reserva de B tiene que seguir
        // vigente: si el trabajo global no respetara el aislamiento, este
        // liberacion seria la consecuencia.
        $liberadas = app(ContextoTenant::class)->ejecutarAislado(
            (string) $a->id,
            fn () => app(LiberadorDeReservasVencidas::class)->liberarPara((string) $a->id)
        );

        $liberadas->assertCount(0);

        $operacionesB = $this->dentroDe($b, fn (): array => Operacion::query()
            ->pluck('estado_contrato')->map(fn ($e): string => $e->value)->all());

        $this->assertSame(
            [EstadoContrato::Vigente->value],
            $operacionesB,
            'La reserva de B no deberia haberse tocado.'
        );
    }

    /**
     * Crea una reserva de venta con su comision calculada sobre el pactado.
     */
    private function crearReserva(
        \App\Models\Inmobiliaria $inmobiliaria,
        Oferta $oferta,
        \App\Models\Cliente $cliente,
        \App\Models\Usuario $corredor,
        Carbon $expiraEn,
        int $valorPactado,
    ): Operacion {
        return $this->dentroDe($inmobiliaria, function () use (
            $inmobiliaria,
            $oferta,
            $cliente,
            $corredor,
            $expiraEn,
            $valorPactado
        ): Operacion {
            $porcentaje = 3.0;

            $operacion = Operacion::query()->create([
                'tenant_id' => $inmobiliaria->id,
                'oferta_id' => $oferta->id,
                'propiedad_id' => $oferta->propiedad_id,
                'cliente_id' => $cliente->id,
                'corredor_id' => $corredor->id,
                'tipo' => 'reserva',
                'valor_pactado' => $valorPactado,
                'valor_lista_oferta' => $oferta->valor_lista,
                'porcentaje_comision' => $porcentaje,
                // 3% de 118 millones, no de 120. Ver CalculadoraDeComision.
                'comision_monto' => \App\Services\Operaciones\CalculadoraDeComision::calcular($valorPactado, $porcentaje),
                'modalidad_comision' => 'porcentaje',
                'creada_en' => Carbon::now(),
                'expira_en' => $expiraEn,
                'renovaciones' => 0,
                'estado_contrato' => EstadoContrato::Vigente->value,
            ]);

            // La oferta pasa a reservada, que es lo que hace que el tramo
            // cerrado que se mide despues sea el correcto.
            $reservada = \App\Models\Estado::query()
                ->where('tenant_id', $inmobiliaria->id)
                ->where('clave_sistema', EstadoComercial::Reservada->value)
                ->firstOrFail();

            app(\App\Services\Estados\RegistradorDeCambioEstado::class)->registrar(
                oferta: $oferta->refresh(),
                estadoNuevo: $reservada,
                motivo: 'Reserva tomada por el cliente',
                usuarioId: (string) $corredor->getKey(),
            );

            return $operacion;
        });
    }
}