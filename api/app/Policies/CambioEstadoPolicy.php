<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CambioEstado;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeCartera;

/**
 * Quién ve el historial de estados.
 *
 * CASO QUE IMPORTA: EL USUARIO DE LA FOTOGRAFIA
 * ---------------------------------------------
 * `cambios_estado` tiene su propio `usuario_id`, el que hizo el cambio. Ese es
 * el corredor que hizo la accion, y puede ser una persona distinta del que
 * lleva la oferta hoy: un traspaso de cartera deja historial de gente anterior.
 *
 * El filtro NO puede usar ese `usuario_id`, por una razon concreta: si lo
 * usara, al traspasar una cartera el corredor nuevo no veria nada del historial
 * anterior, que es justo la mitad del valor del producto. La verdad de "quien
 * lleva esto" es la oferta; `usuario_id` es el registro de "quien lo hizo".
 *
 * Por eso esta policy delega en la oferta y no mira el `usuario_id`.
 */
class CambioEstadoPolicy
{
    use PolicyDeCartera;

    /**
     * Ver un tramo.
     *
     * No se puede decidir sin cargar la oferta, porque el tramo no tiene el
     * `corredor_id` que manda. Se carga y se delega en OfertaPolicy.
     */
    public function view(Usuario $usuario, CambioEstado $cambio): bool
    {
        $oferta = $cambio->oferta;

        if ($oferta === null) {
            // Una fila historica sin oferta no deberia existir por la FK. Si
            // aparece, no se muestra: el criterio es no mostrar ante datos
            // inconsistentes, no adivinar.
            return false;
        }

        return $this->dentroDeCartera($usuario, $oferta->corredor_id);
    }

    /**
     * NO existe permiso para escribir en el historial.
     *
     * No hay `create` ni `update` en esta policy, y es a proposito: la unica
     * via de escritura es
     * App\Services\Estados\RegistradorDeCambioEstado::registrar(), que escribe
     * el tramo cerrado y el nuevo en la misma transaccion y calcula la duracion.
     *
     * Un permiso de escritura aca seria una puerta trasera a la tabla mas
     * importante del producto.
     */
    public function escribir(Usuario $usuario, CambioEstado $cambio): bool
    {
        return false;
    }
}