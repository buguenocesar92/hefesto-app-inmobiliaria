<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Los estados que el MOTOR necesita conocer, por encima de la configuracion.
 *
 * POR QUE EXISTE ESTE ENUM SI LOS ESTADOS SON DATOS
 * -------------------------------------------------
 * Los estados comerciales viven en la tabla `estados`, que cada inmobiliaria
 * configura. Pero hay cuatro conceptos que el motor tiene que reconocer aunque
 * la inmobiliaria los llame como quiera, porque de ellos dependen las reglas
 * automaticas:
 *
 *   - Disponible: es el estado al que vuelve una propiedad cuando se cae una
 *     reserva. Si la inmobiliaria lo llama "en oferta", la liberacion tiene que
 *     poder encontrarlo.
 *   - Reservada: es el estado cuya DURACION es el indicador que el producto
 *     vende. Una reserva de ocho meses es ocho meses sin vender.
 *   - Escriturado: fin de una venta.
 *   - Arrendada: fin de un arriendo.
 *
 * La correspondencia se hace por `clave` en la tabla `estados` (columna
 * `clave_sistema`), no por el nombre. Cambiar el texto visible de un estado no
 * rompe el motor; borrarlo si.
 */
enum EstadoComercial: string
{
    case Disponible = 'disponible';
    case Reservada = 'reservada';
    case Escriturado = 'escriturado';
    case Arrendada = 'arrendada';

    /**
     * Estados que no tienen estado siguiente: la oferta salio del circulo.
     */
    public function esTerminal(): bool
    {
        return match ($this) {
            self::Escriturado, self::Arrendada => true,
            default => false,
        };
    }

    /**
     * Un estado en el que la propiedad NO esta vendiendo ni alquilándose: hay que
     * sacarla del mercado.
     */
    public function esRetirado(): bool
    {
        return $this === self::Escriturado || $this === self::Arrendada;
    }
}