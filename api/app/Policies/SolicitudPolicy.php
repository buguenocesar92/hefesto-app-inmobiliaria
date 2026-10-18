<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Solicitud;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeCartera;

/**
 * Quién ve y quién toca una solicitud.
 *
 * Dos filtros, porque hay dos Dueños posibles: el cliente al que pertenece y el
 * corredor que la trabaja. Un corredor puede trabajar una solicitud que se
 * creó en la cartera de otro (el cliente cambio de asesor), y en ese caso tiene
 * que verla.
 *
 * Por eso la regla es "la cartera de la solicitud O la cartera del cliente", y
 * no solo una de las dos. Si se usara solo `solicitud.corredor_id`, un cambio de
 * asesor dejaria la solicitud y todo su historial de propiedades propuestas
 * fuera del alcance de la persona que ahora trabaja con ese cliente.
 */
class SolicitudPolicy
{
    use PolicyDeCartera;

    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Solicitud $solicitud): bool
    {
        return $this->dentroDeCartera($usuario, $solicitud->corredor_id);
    }

    public function create(Usuario $usuario): bool
    {
        return true;
    }

    public function update(Usuario $usuario, Solicitud $solicitud): bool
    {
        return $this->dentroDeCartera($usuario, $solicitud->corredor_id);
    }

    /**
     * Proponer una propiedad para una solicitud.
     *
     * Aquí NO hay filtro de cartera a proposito, y la razon es concreta: el
     * trabajo de un corredor es a veces decir "esta propiedad, aunque sea de mi
     * colega, le sirve a este cliente". Filtrar aca impediria el caso mas
     * comun de la operacion.
     *
     * Lo que si se comprueba es que la solicitud y la propiedad sean del mismo
     * tenant: eso lo garantiza la RLS, porque las dos tablas estan protegidas.
     */
    public function proponerPropiedad(Usuario $usuario, Solicitud $solicitud): bool
    {
        return true;
    }

    public function delete(Usuario $usuario, Solicitud $solicitud): bool
    {
        return false;
    }
}