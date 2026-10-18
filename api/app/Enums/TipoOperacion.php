<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Tipo de operacion comercial de una oferta.
 *
 * Una propiedad puede tener una oferta de cada tipo al mismo tiempo. Esa es la
 * razon de que esto sea un enum de DOS valores y no un campo unico: 'venta y
 * arriendo' no es un valor mas, son dos ofertas.
 *
 * @see \App\Models\Oferta
 */
enum TipoOperacion: string
{
    case Venta = 'venta';
    case Arriendo = 'arriendo';

    /** Etiqueta para la interfaz. */
    public function etiqueta(): string
    {
        return match ($this) {
            self::Venta => 'Venta',
            self::Arriendo => 'Arriendo',
        };
    }
}