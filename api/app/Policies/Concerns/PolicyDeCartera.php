<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\Usuario;

/**
 * El segundo filtro: dentro de una inmobiliaria, que ve cada corredor.
 *
 * POR QUE HACE FALTA SI YA HAY RLS
 * --------------------------------
 * La RLS responde a "de quien es esta fila". Esta pieza responde a una pregunta
 * distinta y mas fina: "esta fila es de la inmobiliaria, pero es MI Negocio".
 *
 * Son cosas que en una inmobiliaria con dos personas no se notan y en una con
 * veinte son el pan de cada dia. Un corredor ve su cartera; el administrador ve
 * la casa entera. Sin esto, todos verian todos.
 *
 * LA REGLA, EN DOS PARTES
 * -----------------------
 *   1. El administrador tiene alcance total.
 *   2. Para todos los demas, la fila tiene que traer su `corredor_id` en el
 *      alcance.
 *
 * Y una decision que conviene que sea explicita: un recurso SIN corredor
 * asignado (`corredor_id` NULL) NO es visible para el corredor, solo para el
 * administrador. Podria parecer justo al reves ("nadie lo tiene, asi que es de
 * todos"), pero en la practica ese recurso es la cartera sin dueño del
 * administrador, y mostrarlo a todos seria el camino mas corto a una visita
 * duplicada entre dos corredores.
 *
 * QUE NO SE COMPRUEBA ACA
 * -----------------------
 * El `tenant_id`. La RLS ya lo garantiza y, si esta clase lo comprobara, el
 * filtro quedaria con la falsa impresion de ser el unico que protege.
 */
trait PolicyDeCartera
{
    /**
     * ¿El usuario tiene alcance sobre un recurso con este corredor asignado?
     *
     * @param  string|null  $corredorId  `corredor_id` del recurso.
     */
    protected function dentroDeCartera(Usuario $usuario, ?string $corredorId): bool
    {
        if ($usuario->esAdministrador()) {
            return true;
        }

        if ($corredorId === null) {
            return false;
        }

        return $corredorId === (string) $usuario->getKey();
    }
}