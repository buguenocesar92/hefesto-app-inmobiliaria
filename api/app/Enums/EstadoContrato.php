<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Estado del contrato de una operacion.
 *
 * Distingue `expirada` (una reserva que se cayo por no confirmar a tiempo) de
 * `anulada` (que alguien la dejo sin efecto antes de vencer). La diferencia no es
 * cosmetica: una reserva vencida es un problema del PROCESO (nadie la
 * confirmo a tiempo), y una anulada es una decision. El tablero las cuenta
 * distinto.
 *
 * `retornada` es el retorno de un arriendo: termino el contrato y no se renovo.
 * No es un evento neutro, es una propiedad que vuelve al mercado y hay que
 * medir tambien cuanto tarda en arrendar de nuevo.
 */
enum EstadoContrato: string
{
    case Vigente = 'vigente';
    case Expirada = 'expirada';
    case Confirmada = 'confirmada';
    case Anulada = 'anulada';
    case PorRenovar = 'por_renovar';
    case Renovada = 'renovada';
    case Retornado = 'retornado';
    case Terminada = 'terminada';

    /**
     * Estados en los que la operacion sigue ocupando la propiedad y por lo tanto
     * todavia puede vencerse o requerir gestion.
     */
    public function estaVigente(): bool
    {
        return match ($this) {
            self::Vigente, self::PorRenovar, self::Renovada => true,
            default => false,
        };
    }
}