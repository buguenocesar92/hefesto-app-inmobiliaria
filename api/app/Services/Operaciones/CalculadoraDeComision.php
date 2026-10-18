<?php

declare(strict_types=1);

namespace App\Services\Operaciones;

use InvalidArgumentException;

/**
 * Calculadora de comisiones.
 *
 * LA REGLA, EN UNA LINEA
 * -----------------------
 *     comision = valor PACTADO x porcentaje
 *
 * NO el valor de lista. Es el error clasico de este rubro y por eso la
 * operacion vive en una clase sola, con una prueba que la fija, en vez de
 * repartida en los controladores.
 *
 * POR QUE NO SE PUEDE CALCULAR "EN EL VUELO"
 * -------------------------------------------
 * Tres motivos, en orden de gravedad:
 *
 *  1. La comision es un dato que hay que COBRAR. Va en la tabla, porque una
 *     comision recalculada cambia de valor cuando cambia el valor pactado, y
 *     el corredor ya vio un correo con la cifra anterior.
 *  2. Se negocia. Un 3% en vez de 3,5% para una operacion de arriendo de
 *     larga duracion es una decision, y queda registrada. Si se recalculara
 *     sola, esa decision se perderia.
 *  3. Dos personas tienen que ver el mismo numero. Si uno lo recalcula y otro
 *     lee la columna, se pueden desincronizar por redondeo.
 *
 * COMO SE REDONDEA
 * ----------------
 * Se redondea al peso, hacia arriba cuando hay resto. Redondear al peso hacia
 * abajo sistemicamente le cuesta plata al corredor; hacia arriba le puede
 * costar un peso. La eleccion es del negocio y quedo anotada en docs/02.
 */
final class CalculadoraDeComision
{
    /**
     * Comision de una operacion, en pesos, redondeada al peso.
     *
     * @throws InvalidArgumentException si el pactado no es positivo o el
     *                                   porcentaje esta fuera de rango.
     */
    public static function calcular(int $valorPactado, float $porcentajeComision): int
    {
        if ($valorPactado <= 0) {
            throw new InvalidArgumentException(
                'El valor pactado tiene que ser positivo. Un pactado de ' . $valorPactado
                . ' significa que la operacion no esta cerrada.'
            );
        }

        if ($porcentajeComision <= 0 || $porcentajeComision > 100) {
            throw new InvalidArgumentException(
                'El porcentaje de comision tiene que estar entre 0 y 100. '
                . 'Se recibio ' . $porcentajeComision . '.'
            );
        }

        $exacto = $valorPactado * ($porcentajeComision / 100);

        return (int) ceil($exacto);
    }

    /**
     * Comision mas un monto adicional fijo.
     *
     * El caso tipico es el arriendo: en vez de un porcentaje, la comision es
     * "un mes de arriendo". Eso NO se modela como un porcentaje enorme del
     * valor pactado (un mes sobre doce meses es 8,33%, y redondeado por mes
     * el numero sale mal), sino como un monto adicional. Ver
     * App\Models\Operacion::modalidad_comision.
     */
    public static function calcularConAdicional(
        int $valorPactado,
        float $porcentajeComision,
        int $adicional = 0,
    ): int {
        if ($adicional < 0) {
            throw new InvalidArgumentException(
                'El monto adicional de comision no puede ser negativo.'
            );
        }

        return self::calcular($valorPactado, $porcentajeComision) + $adicional;
    }

    /**
     * Comision de una operacion de arriendo expresada en meses.
     *
     * Un mes de arriendo sobre el pactado. Se separa de `calcular()` porque
     * el resultado NO es un porcentaje del pactado: es una fraccion del tiempo
     * del contrato, y esa distincion es la que impide que alguien la convierta
     * despues en un 8,33%.
     *
     * @param  int  $meses  duracion pactada en meses (puede ser 12, 24, 36...).
     */
    public static function comisionDeArriendoPorMeses(int $valorPactado, int $meses): int
    {
        if ($meses <= 0) {
            throw new InvalidArgumentException(
                'La duracion en meses tiene que ser positiva para calcular la comision.'
            );
        }

        return (int) ceil($valorPactado / 12 * $meses);
    }
}