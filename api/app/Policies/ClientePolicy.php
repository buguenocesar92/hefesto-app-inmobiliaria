<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cliente;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeCartera;

/**
 * Quién ve y quién toca un cliente.
 *
 * Los datos de un cliente son personales bajo la Ley 19.628, asi que el
 * listado ya viene filtrado por cartera en el controlador y la policy decide
 * sobre el recurso individual.
 *
 * Un corredor NO ve clientes de otro corredor aunque sean de la misma
 * inmobiliaria. Esa es la regla que la prueba
 * `CarteraDeCorredorTest` fija, y es distinta del aislamiento entre
 * inmobiliarias: aqui las dos filas son del mismo `tenant_id`, y las separa la
 * cartera.
 */
class ClientePolicy
{
    use PolicyDeCartera;

    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Cliente $cliente): bool
    {
        return $this->dentroDeCartera($usuario, $cliente->corredor_id);
    }

    public function create(Usuario $usuario): bool
    {
        return true;
    }

    public function update(Usuario $usuario, Cliente $cliente): bool
    {
        return $this->dentroDeCartera($usuario, $cliente->corredor_id);
    }

    /**
     * No se borran clientes. Se desactivan.
     *
     * Un cliente tiene visitas y operaciones que hay que poder leer meses
     * despues, para un reclamo o para una referencia comercial. Borrarlo
     * dejaria filas huerfanas y, peor, borraria informacion personal sin dejar
     * rastro de que existió, que es justamente lo que la Ley 19.628 pide
     * evitar.
     */
    public function delete(Usuario $usuario, Cliente $cliente): bool
    {
        return false;
    }
}