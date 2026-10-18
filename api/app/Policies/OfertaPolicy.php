<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Oferta;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeCartera;

/**
 * Quién ve y quién toca una oferta.
 *
 * CASO ESPECIFICO QUE IMPORTA
 * ---------------------------
 * Una oferta tiene su propio `corredor_id`, que puede no ser el de la propiedad.
 * El filtro usa el de la OFERTA, no el de la propiedad: si el dueño de la
 * ficha ve una oferta que se le traspaso a otra persona, y viceversa, lo que se
 * filtra es una linea de tiempo, no una direccion.
 *
 * Y hay un detalle de seguridad en el cambio de estado: lo puede hacer quien
 * tenga la oferta, sin excepcion. No hay un "estado reservado" que solo puede
 * cambiar el jefe: en una inmobiliaria chica el que conoce el caso es el
 * corredor, y poner una excepcion inventada haria que las reservas quedaran
 * congeladas por falta de un permiso.
 */
class OfertaPolicy
{
    use PolicyDeCartera;

    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Oferta $oferta): bool
    {
        return $this->dentroDeCartera($usuario, $oferta->corredor_id);
    }

    public function create(Usuario $usuario): bool
    {
        return true;
    }

    public function update(Usuario $usuario, Oferta $oferta): bool
    {
        return $this->dentroDeCartera($usuario, $oferta->corredor_id);
    }

    /**
     * Cambiar el estado es parte de operar la oferta, asi que va con el mismo
     * permiso que modificarla.
     */
    public function cambiarEstado(Usuario $usuario, Oferta $oferta): bool
    {
        return $this->dentroDeCartera($usuario, $oferta->corredor_id);
    }

    /**
     * Ver el historial de estados.
     *
     * Concede lo mismo que ver la oferta: el historial es la lectura ampliada
     * de la misma cosa, y separarlos solo produciria un 404 en el indicador de
     * permanencia de la ficha.
     */
    public function verHistorial(Usuario $usuario, Oferta $oferta): bool
    {
        return $this->dentroDeCartera($usuario, $oferta->corredor_id);
    }

    public function delete(Usuario $usuario, Oferta $oferta): bool
    {
        return $usuario->esAdministrador();
    }
}