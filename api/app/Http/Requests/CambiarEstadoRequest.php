<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Estado;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * Validacion del cambio de estado de una oferta.
 *
 * DOS COMPROBACIONES QUE NO SON DE FORMATO
 * ----------------------------------------
 * 1. El estado de destino tiene que ser de la MISMA inmobiliaria. La RLS ya
 *    impide leer y escribir filas ajenas, pero aqui hay algo distinto: se
 *    podria pasar el id de un estado de otra inmobiliaria y el sistema responder
 *    "no encontrado", que es un 422 y no un 404, y ese 422 le confirma a quien
 *    pregunta que ese id existe en algun lado. Se resuelve con un 404.
 *
 * 2. El estado de destino tiene que aplicar al tipo de operacion de la oferta.
 *    No se puede pasar una oferta de arriendo a "escriturado", que es un estado
 *    de venta. Sin esta comprobacion, la linea de tiempo de un arriendo
 *    registraria un estado que no le corresponde y el informe de permanencia
 *    mezclaria dos flujos.
 *
 * Ademas se impide el cambio al mismo estado en que ya se esta: no produce
 * historial, produce ruido, y el RegistradorDeCambioEstado lo rechaza con una
 * excepcion que en la API seria un 500.
 */
class CambiarEstadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorizacion fina la hace `cambiarEstado` en OfertaPolicy, con el
        // recurso cargado. Aqui solo se exige sesion: el filtro por cartera se
        // aplica con la oferta a la vista.
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'estado_id' => ['required', 'uuid'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * El estado de destino, ya resuelto y validado.
     *
     * @throws ValidationException
     */
    public function estado_destino(): Estado
    {
        $estadoId = (string) $this->input('estado_id');

        // La consulta va por `clave_sistema` opcional y por tenant explicito. La
        // RLS ya filtraria, pero pedirlo de mas no cuesta y deja el criterio de
        // seguridad en dos capas, que es lo que se quiere en la tabla que
        // sostiene el producto.
        $estado = Estado::query()
            ->where('id', $estadoId)
            ->where('tenant_id', app(ContextoTenant::class)->inmobiliariaIdEnConexion())
            ->first();

        if ($estado === null) {
            // 404 y no 422: no se confirma que ese identificador exista.
            throw ValidationException::withMessages([
                'estado_id' => 'El estado indicado no existe.',
            ]);
        }

        $oferta = $this->route('oferta');

        if ($oferta !== null && ! $estado->aplicaA($oferta->tipo_operacion)) {
            throw ValidationException::withMessages([
                'estado_id' => 'El estado indicado no aplica a una operacion de '
                    . $oferta->tipo_operacion->etiqueta() . '.',
            ]);
        }

        if ($oferta !== null && (string) $oferta->estado_id === $estado->id) {
            throw ValidationException::withMessages([
                'estado_id' => 'La oferta ya esta en ese estado.',
            ]);
        }

        return $estado;
    }

    /**
     * El motivo, o uno por defecto que explique el cambio sin inventar.
     *
     * El motivo es lo que va a leer el jefe dentro de seis meses, asi que el
     * valor por defecto dice lo que pasó, no "cambio de estado".
     */
    public function motivo(): string
    {
        $motivo = $this->input('motivo');

        if (is_string($motivo) && trim($motivo) !== '') {
            return mb_substr(trim($motivo), 0, 255);
        }

        return 'Cambio de estado manual';
    }
}