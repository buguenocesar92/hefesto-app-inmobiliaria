<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Operacion;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeCartera;

/**
 * Quién ve y quién toca una operación (reserva o arriendo).
 *
 * LA RESERVA Y LA COMISION
 * ------------------------
 * Quien puede tocar una operacion puede ver `valor_pactado` y `comision_monto`.
 * Son las dos cifras mas sensibles del negocio: una revela que el dueño
 * acepto 120 millones cuando la ficha estaba en 140, y la otra es la diferencia
 * entre lo que le rindio el mes al corredor y lo que se le pago.
 *
 * Por eso aqui el corredor NO tiene alcance automatico: el comision se ve, pero
 * el valor pactado y la comision son del administrador. Ver `verDinero`.
 */
class OperacionPolicy
{
    use PolicyDeCartera;

    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    /**
     * Ver la operación: el corredor ve su propia reserva (tiene que saber si
     * el cliente la confirmo), pero no ve las cifras de dinero.
     */
    public function view(Usuario $usuario, Operacion $operacion): bool
    {
        return $this->dentroDeCartera($usuario, $operacion->corredor_id);
    }

    /**
     * El dinero: valor pactado, porcentaje y monto de comisión.
     *
     * Solo el administrador. Es una decision conscious de alcance, no una
     * consecuencia de la RLS: la fila es de la misma inmobiliaria y la RLS la
     * deja pasar.
     */
    public function verDinero(Usuario $usuario, Operacion $operacion): bool
    {
        return $usuario->esAdministrador()
            && $this->dentroDeCartera($usuario, $operacion->corredor_id);
    }

    /**
     * Crear reservas y arriendos lo puede hacer cualquiera de los dos roles.
     */
    public function create(Usuario $usuario): bool
    {
        return true;
    }

    public function update(Usuario $usuario, Operacion $operacion): bool
    {
        return $this->dentroDeCartera($usuario, $operacion->corredor_id);
    }

    /**
     * Confirmar una reserva (dejarla efectiva) y renovar un arriendo.
     *
     * Solo el administrador. Son los dos actos que mueven dinero y que no se
     * pueden deshacer: si un corredor confirma una reserva, el departamento deja
     * de estar disponible para todos los demas.
     */
    public function confirmar(Usuario $usuario, Operacion $operacion): bool
    {
        return $usuario->esAdministrador();
    }

    public function delete(Usuario $usuario, Operacion $operacion): bool
    {
        return false;
    }
}