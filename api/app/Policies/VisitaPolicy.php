<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Visita;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeCartera;

/**
 * Quién ve y quién toca una visita.
 *
 * Una visita es el seguimiento de un cliente con una propiedad. El filtro usa
 * el `corredor_id` de la visita, que es quien la realiza, y no el del cliente:
 * un visitador asignado a una cartera ajena tiene que poder registrar lo que
 * vio.
 */
class VisitaPolicy
{
    use PolicyDeCartera;

    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Visita $visita): bool
    {
        return $this->dentroDeCartera($usuario, $visita->corredor_id);
    }

    public function create(Usuario $usuario): bool
    {
        return true;
    }

    /**
     * Registrar el resultado de una visita.
     *
     * Va con el mismo permiso que modificarla: es la misma informacion. El
     * campo `resultado` es el que explica por que se cae una reserva, y no
     * tiene mas sensibilidad que el resto de la visita.
     */
    public function update(Usuario $usuario, Visita $visita): bool
    {
        return $this->dentroDeCartera($usuario, $visita->corredor_id);
    }

    public function delete(Usuario $usuario, Visita $visita): bool
    {
        return false;
    }
}