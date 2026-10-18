<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Usuario;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validacion del alta y la edicion de una propiedad.
 *
 * TRES REGLAS QUE NO SON "EL CAMPO ES OBLIGATORIO"
 * -------------------------------------------------
 *
 * 1. UN CORREDOR NO ASIGNA A OTRO CORREDOR. El `corredor_id` viene en el cuerpo
 *    de la peticion, y si se aceptara tal cual, cualquier corredor podria
 *    cargarle su inventario a un colega (o, peor, sacarselo). Para un corredor,
 *    el corredor asignado es siempre el mismo: el suyo.
 *
 * 2. EL CORREDOR ASIGNADO TIENE QUE SER DE LA MISMA INMOBILIARIA. La RLS impide
 *    leer usuarios ajenos, asi que el id simplemente no se encontraria; pero se
 *    comprueba igual, porque un 422 claro es mejor que un fallo de base.
 *
 * 3. AL MENOS UN PRECIO. Una propiedad con `valor_venta` y `valor_arriendo` en
 *    null no tiene nada que ofrecer y no genera ninguna oferta posible. Si se
 *    corrijera mas adelante en el flujo, el aviso llega tarde.
 */
class GuardarPropiedadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'codigo_interno' => ['nullable', 'string', 'max:40'],
            'direccion' => ['required', 'string', 'max:255'],
            'comuna' => ['required', 'string', 'max:80'],
            'region' => ['nullable', 'string', 'max:80'],

            'tipo_propiedad' => ['nullable', 'string', 'max:40'],

            'dormitorios' => ['nullable', 'integer', 'min:0', 'max:50'],
            'banos' => ['nullable', 'integer', 'min:0', 'max:50'],
            'estacionamientos' => ['nullable', 'integer', 'min:0', 'max:50'],
            'superficie_m2' => ['nullable', 'numeric', 'gt:0', 'max:100000'],
            'anio_construccion' => ['nullable', 'integer', 'min:1800', 'max:2100'],

            'piso' => ['nullable', 'string', 'max:40'],
            'departamento' => ['nullable', 'string', 'max:40'],
            'amoblado' => ['nullable', 'boolean'],

            'descripcion' => ['nullable', 'string', 'max:5000'],

            'valor_venta' => ['nullable', 'integer', 'min:0'],
            'valor_arriendo' => ['nullable', 'integer', 'min:0'],

            'propietario_nombre' => ['nullable', 'string', 'max:160'],
            'propietario_telefono' => ['nullable', 'string', 'max:30'],
            'propietario_correo' => ['nullable', 'email', 'max:160'],

            'corredor_id' => ['nullable', 'uuid'],
            'activa' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'direccion.required' => 'La direccion es obligatoria: sin ella la propiedad no se puede ubicar.',
            'comuna.required' => 'La comuna es obligatoria: los informes de permanencia se agrupan por comuna.',
        ];
    }

    /**
     * Comprobaciones que dependen del rol y del contenido.
     */
    public function withValidator(\Illuminate\Validation\Validator $validador): void
    {
        $validador->after(function (\Illuminate\Validation\Validator $v): void {
            $usuario = $this->user();

            if ($usuario === null) {
                return;
            }

            // REGLA 1: un corredor se asigna a si mismo, y solo eso.
            if (! $usuario->esAdministrador()) {
                $enviado = $this->input('corredor_id');

                if ($enviado !== null && (string) $enviado !== (string) $usuario->getKey()) {
                    $v->errors()->add(
                        'corredor_id',
                        'Un corredor solo puede registrar propiedades en su propia cartera.'
                    );
                }
            }

            // REGLA 2: el corredor asignado tiene que existir en esta
            // inmobiliaria. Sin RLS se comprobaria con una consulta; con RLS, si
            // el usuario es de otra inmobiliaria, no se encuentra y se avisa.
            $corredorId = $this->corredorIdResuelto();

            if ($corredorId !== null) {
                $existe = Usuario::query()
                    ->where('id', $corredorId)
                    ->where('tenant_id', $usuario->tenant_id)
                    ->exists();

                if (! $existe) {
                    $v->errors()->add('corredor_id', 'El corredor indicado no pertenece a esta inmobiliaria.');
                }
            }

            // REGLA 3: al menos un precio de lista.
            //
            // Solo en el alta. En una edicion puede venir una carga parcial
            // (por ejemplo, solo la descripcion) y exigir los precios ahi
            // rechazaria una edicion legitima de un formulario que si los tiene
            // guardados. En el alta no hay valor previo que mirar, asi que es
            // el momento de avisar.
            if ($this->isMethod('POST')) {
                $tienePrecio = $this->filled('valor_venta') || $this->filled('valor_arriendo');

                if (! $tienePrecio) {
                    $v->errors()->add(
                        'valor_venta',
                        'Indica al menos un precio: el de venta, el de arriendo o ambos.'
                    );
                }
            }
        });
    }

    /**
     * El corredor asignado, con el caso del corredor resolvedo.
     */
    public function corredorIdResuelto(): ?string
    {
        $enviado = $this->input('corredor_id');

        if ($enviado !== null && $enviado !== '') {
            return (string) $enviado;
        }

        $usuario = $this->user();

        return $usuario === null ? null : (string) $usuario->getKey();
    }

    /**
     * Los atributos que se guardan, con el `tenant_id` del contexto y sin el
     * corredor si el usuario es un corredor (siempre es el suyo).
     *
     * @return array<string, mixed>
     */
    public function atributosParaGuardar(string $tenantId): array
    {
        $atributos = $this->validated();
        unset($atributos['corredor_id']);

        // El corredor se resuelve siempre: para el administrador puede venir en
        // la peticion, y para el corredor es el suyo por definicion.
        $atributos['corredor_id'] = $this->corredorIdResuelto();
        $atributos['tenant_id'] = $tenantId;

        return $atributos;
    }
}