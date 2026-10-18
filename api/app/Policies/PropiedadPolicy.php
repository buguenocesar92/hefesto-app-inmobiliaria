<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Propiedad;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeCartera;

/**
 * Quién ve y quién toca una propiedad.
 *
 * La regla es la del trait PolicyDeCartera: el administrador ve todas, el
 * corredor solo las que tiene asignadas.
 *
 * SOBRE LAS FOTOS
 * ---------------
 * Una foto puede tener datos personales: un pasillo con una carta con un
 * nombre, una ventana con una direccion visible. Por eso ver una foto exige el
 * mismo permiso que ver la ficha, y no es un permiso mas abierto. Ver
 * FotoPropiedadPolicy.
 */
class PropiedadPolicy
{
    use PolicyDeCartera;

    public function viewAny(Usuario $usuario): bool
    {
        // Ambos ven el listado: el del corredor sale filtrado por cartera en el
        // controlador, no en la policy. La policy decide sobre el RECURSO.
        return true;
    }

    public function view(Usuario $usuario, Propiedad $propiedad): bool
    {
        return $this->dentroDeCartera($usuario, $propiedad->corredor_id);
    }

    /**
     * Crear propiedades lo puede hacer cualquiera de los dos roles: un corredor
     * carga el inventario que le encargo su jefe.
     *
     * Lo que NO se permite es crearla en la cartera de otro. Se comprueba en
     * el Request, donde el `corredor_id` viene de la peticion.
     * Ver GuardarPropiedadRequest.
     */
    public function create(Usuario $usuario): bool
    {
        return true;
    }

    public function update(Usuario $usuario, Propiedad $propiedad): bool
    {
        return $this->dentroDeCartera($usuario, $propiedad->corredor_id);
    }

    /**
     * Borrar es en la practica desactivar: la propiedad tiene historial de
     * estados, visitas y operaciones que no se pueden perder. El metodo se deja
     * para cuando no exista `borrar` en el recurso.
     */
    public function delete(Usuario $usuario, Propiedad $propiedad): bool
    {
        return $usuario->esAdministrador();
    }
}