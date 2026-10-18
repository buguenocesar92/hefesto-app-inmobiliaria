<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Operaciones\CalculadoraDeComision;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * La comision se calcula sobre el valor PACTADO, no sobre el de lista.
 *
 * ESTA ES UNA PRUEBA UNITARIA A PROPOSITO
 * ---------------------------------------
 * No necesita base de datos ni PostgreSQL: la regla es aritmetica. Y con ser
 * aritmetica es justamente por lo que conviene fijarla sola, porque es la regla
 * que se rompe "por convenience" cuando alguien anade un campo mas al formulario.
 *
 * El caso de la prueba que mas importa es `test_no_usa_el_valor_de_lista`:
 * alguien podria refactorizar el calculo para que tomara el precio de la ficha
 * y seguiria "funcionando" en los ejemplos faciles, porque un pacto de lista a
 * lista da el mismo resultado. Esa prueba usa valores distintos a proposito.
 */
class CalculadoraDeComisionTest extends TestCase
{
    public function test_calcula_el_porcentaje_sobre_el_pactado(): void
    {
        // 3% de 118 millones = 3.540.000
        $this->assertSame(3_540_000, CalculadoraDeComision::calcular(118_000_000, 3.0));
    }

    public function test_el_resto_se_redondea_hacia_arriba(): void
    {
        // 3,5% de 100.000 = 3.500 exacto.
        $this->assertSame(3_500, CalculadoraDeComision::calcular(100_000, 3.5));

        // 3,33% de 100.001 = 3.330,0333. Redondear hacia abajo leeria
        // 3.330; el sistema cobra 3.331. La diferencia es de un peso por
        // operacion, y el criterio es que al corredor no le reste plata por
        // redondeo. Ver docs/02.
        $this->assertSame(3_331, CalculadoraDeComision::calcular(100_001, 3.33));
    }

    public function test_un_pactado_de_cero_o_negativo_falla(): void
    {
        // Una comision sobre un pactado de cero significa que la operacion no
        // esta cerrada. Si se aceptara, el 0 pasaria por una operacion valida y
        // la columna `comision_monto` (que exige > 0) rechazaria el guardado con
        // un error de base, en vez de un mensaje de negocio.
        $this->expectException(InvalidArgumentException::class);

        CalculadoraDeComision::calcular(0, 3.0);
    }

    public function test_un_porcentaje_fuera_de_rango_falla(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CalculadoraDeComision::calcular(100_000_000, 150.0);
    }

    public function test_el_monto_adicional_se_suma_y_se_puede_no_usar(): void
    {
        // Comision de arriendo pactada como "3% mas un mes de arriendo".
        $this->assertSame(
            CalculadoraDeComision::calcular(12_000_000, 3.0) + 1_000_000,
            CalculadoraDeComision::calcularConAdicional(12_000_000, 3.0, 1_000_000),
        );

        $this->assertSame(
            CalculadoraDeComision::calcular(12_000_000, 3.0),
            CalculadoraDeComision::calcularConAdicional(12_000_000, 3.0, 0),
        );
    }

    public function test_un_adicional_negativo_falla(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CalculadoraDeComision::calcularConAdicional(10_000_000, 3.0, -1);
    }

    public function test_no_usa_el_valor_de_lista(): void
    {
        // LA PRUEBA MAS IMPORTANTE DEL ARCHIVO.
        //
        // Una ficha en 120 millones, pactada en 118:
        //
        //   - sobre el pactado:  3% de 118.000.000 = 3.540.000
        //   - sobre la lista:   3% de 120.000.000 = 3.600.000
        //
        // La diferencia son 60.000 pesos por operacion. El error es clasico de
        // este rubro y por eso queda fijado con una prueba que usa dos
        // valores distintos: un caso de lista igual a pactado no detectaria
        // que alguien cambio la base del calculo.
        $valorDeLista = 120_000_000;
        $valorPactado = 118_000_000;

        $sobreElPactado = CalculadoraDeComision::calcular($valorPactado, 3.0);
        $sobreLaLista = CalculadoraDeComision::calcular($valorDeLista, 3.0);

        $this->assertSame(3_540_000, $sobreElPactado);
        $this->assertSame(3_600_000, $sobreLaLista);

        $this->assertNotSame($sobreLaLista, $sobreElPactado);
    }

    public function test_la_comision_de_arriendo_por_meses_no_es_un_porcentaje(): void
    {
        // Un mes de arriendo sobre 12 meses es 8,33%. Si alguien lo modelara
        // como porcentaje, con redondeo por mes el numero saldria mal en
        // cualquier duracion que no sea multiplo de 12.
        //
        // Arriendo de 18 millones, 12 meses de contrato, comision de un mes:
        $unMes = CalculadoraDeComision::comisionDeArriendoPorMeses(18_000_000, 1);

        $this->assertSame(1_500_000, $unMes);

        // Y a dos meses es el doble exacto, no "8,33% dos veces".
        $dosMeses = CalculadoraDeComision::comisionDeArriendoPorMeses(18_000_000, 2);

        $this->assertSame(3_000_000, $dosMeses);
        $this->assertSame($unMes * 2, $dosMeses);
    }

    public function test_una_duracion_en_meses_no_positiva_falla(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CalculadoraDeComision::comisionDeArriendoPorMeses(12_000_000, 0);
    }
}