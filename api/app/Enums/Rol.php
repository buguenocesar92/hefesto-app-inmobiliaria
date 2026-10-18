<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Rol de la persona dentro de la inmobiliaria.
 *
 * Son dos, a proposito. Un sistema de roles mas fino se inventaria solo: la
 * pregunta real de una inmobiliaria chica es "esta persona ve toda la casa o
 * solo lo suyo", y esa pregunta tiene dos respuestas.
 *
 * La separacion no es jerarquica de permisos sino de ALCANCE: el administrador
 * ve toda la inmobiliaria, el corredor ve su cartera. Por eso la comprobacion
 * esta en App\Policies\Concerns\PolicyDeCartera y no en un `if ($rol === ...)`
 * esparcido por los controladores.
 */
enum Rol: string
{
    case Administrador = 'administrador';
    case Corredor = 'corredor';

    /** El administrador tiene alcance total dentro de la inmobiliaria. */
    public function esAdministrador(): bool
    {
        return $this === self::Administrador;
    }

    /** El alcance del administrador es la inmobiliaria entera. */
    public function alcanceEsTodaLaCartera(): bool
    {
        return $this === self::Administrador;
    }
}