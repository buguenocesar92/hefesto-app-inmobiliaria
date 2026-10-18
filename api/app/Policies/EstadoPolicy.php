<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Estado;
use App\Models\Usuario;
use App\Policies\Concerns\PolicyDeCartera;

/**
 * Quién ve y quién toca la configuración de estados.
 *
 * ESTA ES LA TABLA QUE HACE MEDIBLE AL PRODUCTO
 * ---------------------------------------------
 * Los estados no son decoracion: son el eje del informe de permanencia. Si un
 * corredor pudiera borrar o renombrar un estado por su cuenta, los promedios
 * historicos cambiarian de significado de un dia para otro.
 *
 * De ahi que:
 *   - leerlos sea abierto (todo el mundo necesita ver los nombres);
 *   - crearlos y modificarlos sea solo del administrador;
 *   - no se puedan borrar los que tienen `clave_sistema`: el motor los necesita
 *     para devolver una propiedad al mercado.
 */
class EstadoPolicy
{
    use PolicyDeCartera;

    public function viewAny(Usuario $usuario): bool
    {
        return true;
    }

    public function view(Usuario $usuario, Estado $estado): bool
    {
        return true;
    }

    public function create(Usuario $usuario): bool
    {
        return $usuario->esAdministrador();
    }

    /**
     * Se puede renombrar, cambiar el color o mover de posicion.
     *
     * `clave_sistema` NO se modifica una vez puesto, y eso lo comprueba el
     * Request de guardado, no esta policy: es una regla de integridad del
     * dato, no un permiso.
     */
    public function update(Usuario $usuario, Estado $estado): bool
    {
        return $usuario->esAdministrador();
    }

    /**
     * Borrar un estado con historial.
     *
     * Prohibido, y no por capricho: un estado con filas en `cambios_estado` es
     * informacion que el producto ya le mostro a alguien. Desvincularlo deja
     * tramos huerfanos y el informe pasa a mentir.
     *
     * Lo que si se permite es desactivarlo: deja de ofrecerse y se puede
     * desasignar de las ofertas, sin romper nada.
     */
    public function delete(Usuario $usuario, Estado $estado): bool
    {
        return false;
    }

    /**
     * Un estado con clave de sistema no se puede eliminar jamas, aunque este
     * vacio. El motor lo necesita.
     */
    public function esRequeridoPorElMotor(Estado $estado): bool
    {
        return $estado->clave_sistema !== null;
    }

    /**
     * Ver el historial de un estado concreto.
     */
    public function verHistorial(Usuario $usuario, Estado $estado): bool
    {
        return true;
    }
}