<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EstadoContrato;
use App\Enums\TipoOperacion;
use App\Models\Cliente;
use App\Models\Oferta;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Validacion del alta de una operacion (reserva o arriendo).
 *
 * LA REGLA QUE DOMINA ESTE ARCHIVO
 * --------------------------------
 * Reserva y arriendo comparten casi todo, pero en fechas no: la reserva caduca,
 * el arriendo termina. Mezclarlas no produce un error raro, produce una reserva
 * sin fecha de vencimiento (que nunca vuelve al mercado y pierde una venta) o un
 * arriendo con fecha de caducidad (que se libera sola mientras sigue arrendado).
 *
 * Por eso el tipo se deduce de lo que viene en la peticion y las fechas se
 * validan como el tipo que corresponde. Y por ultimo se hace una comprobacion
 * cruzada final: con las fechas ya validadas, el tipo deduced tiene que
 * coincidir con las fechas que llegaron. Es la version de PHP de la restriccion
 * `chk_operaciones_vencimiento_por_tipo` de la base, y se escribe aqui para que
 * el error llegue con un mensaje y no como una violacion de constraint.
 *
 * LA COMISION
 * -----------
 * El porcentaje llega en la peticion, pero el monto NO: se calcula en
 * `atributosParaGuardar()` con la regla unica del sistema, sobre el valor
 * pactado. Aceptar un `comision_monto` del cliente seria dejar que cada quien
 * invente su propia comision.
 */
class CrearOperacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La autorizacion fina se hace con la oferta y el cliente ya cargados,
        // en el controlador. Aca se exige sesion.
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'oferta_id' => ['required', 'uuid'],
            'cliente_id' => ['required', 'uuid'],

            // El tipo es obligatorio y explicito. Podria deducirse de las
            // fechas, pero es mejor que quien cierra la operacion lo diga: el
            // error de tipo es el mas caro de todos (una reserva de arriendo).
            'tipo' => ['required', 'in:reserva,arriendo'],

            'valor_pactado' => ['required', 'integer', 'min:1'],

            'porcentaje_comision' => ['required', 'numeric', 'gt:0', 'max:100'],
            'comision_adicional' => ['nullable', 'integer', 'min:0'],

            'expira_en' => ['required_if:tipo,reserva', 'nullable', 'date', 'after:now'],

            'fecha_inicio' => ['required_if:tipo,arriendo', 'nullable', 'date'],
            'fecha_termino' => ['required_if:tipo,arriendo', 'nullable', 'date', 'after_or_equal:fecha_inicio'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'valor_pactado.required' => 'Indica el valor pactado: es el valor del que sale la comision, no el precio de lista.',
            'expira_en.after' => 'La reserva vence en una fecha futura. Sin fecha de vencimiento, la propiedad no vuelve al mercado nunca.',
            'porcentaje_comision.max' => 'El porcentaje de comision va entre 0 y 100.',
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validador): void
    {
        $validador->after(function (\Illuminate\Validation\Validator $v): void {
            $tipo = $this->tipoOperacion();

            // La reserva NO puede traer fechas de contrato, y el arriendo NO
            // puede traer fecha de vencimiento. Si llegan, el tipo que se va a
            // guardar seria ambiguo para las reglas automaticas.
            if ($tipo === TipoOperacion::Venta) {
                if ($this->filled('fecha_inicio') || $this->filled('fecha_termino')) {
                    $v->errors()->add(
                        'fecha_inicio',
                        'Una reserva no tiene fechas de contrato. Use expira_en.'
                    );
                }
            }

            // Y la comprobacion cruzada: si es reserva, la fecha de vencimiento
            // tiene que estar.
            if ($tipo === TipoOperacion::Venta && ! $this->filled('expira_en')) {
                $v->errors()->add(
                    'expira_en',
                    'La reserva necesita fecha de vencimiento: si nadie confirma antes, '
                    . 'la propiedad vuelve al mercado.'
                );
            }

            // TOPE DE SEGURIDAD
            // -----------------
            // Una reserva con dos anos de vencimiento deja la propiedad tomada
            // dos anos sin que nadie la vuelva a mirar, que es exactamente la
            // inercia que este producto existe para medir. El tope va aqui y
            // no solo en el comando de liberacion, porque si se verificara al
            // liberar, la propiedad ya estaria tomada desde el principio.
            $diasMaximos = (int) config('inmobiliaria.reserva.dias_maximos', 30);

            if ($tipo === TipoOperacion::Venta && $this->filled('expira_en')) {
                $tope = $this->fechaActual()->addDays($diasMaximos);

                if ($this->expiraEn() !== null && $this->expiraEn()->greaterThan($tope)) {
                    $v->errors()->add(
                        'expira_en',
                        'La reserva no puede vencerse despues de ' . $diasMaximos
                        . ' dias. Una reserva que espera mas de eso ya no es una reserva.'
                    );
                }
            }
        });
    }

    public function oferta_id(): string
    {
        return (string) $this->input('oferta_id');
    }

    public function cliente_id(): string
    {
        return (string) $this->input('cliente_id');
    }

    public function tipoOperacion(): TipoOperacion
    {
        return TipoOperacion::from((string) $this->input('tipo'));
    }

    /**
     * La fecha de vencimiento, ya convertida.
     *
     * Es obligatoria para una reserva: la devolucion de null seria peor que un
     * error, porque la liberacion automatica no tendria nada que mirar.
     */
    public function expiraEn(): ?Carbon
    {
        $valor = $this->input('expira_en');

        return is_string($valor) && $valor !== '' ? Carbon::parse($valor) : null;
    }

    /**
     * El "hoy" de la peticion.
     *
     * Va por metodo y no por `now()` directo en varios lugares, para que las
     * pruebas puedan fijar la fecha sin pelearse con el reloj. Todas las pruebas
     * del sistema pasan por aca.
     */
    public function fechaActual(): Carbon
    {
        return Carbon::now();
    }

    /**
     * Los atributos de la operacion, con la comision ya calculada.
     *
     * @return array<string, mixed>
     */
    public function atributosParaGuardar(Oferta $oferta, Cliente $cliente, string $corredorId): array
    {
        $tipo = $this->tipoOperacion();

        $valorPactado = (int) $this->input('valor_pactado');
        $porcentaje = (float) $this->input('porcentaje_comision');
        $adicional = (int) ($this->input('comision_adicional') ?? 0);

        // La comision se calcula AQUI y no se acepta desde la peticion. La
        // regla vive en CalculadoraDeComision y se aplica una sola vez.
        $comision = \App\Services\Operaciones\CalculadoraDeComision::calcularConAdicional(
            valorPactado: $valorPactado,
            porcentajeComision: $porcentaje,
            adicional: $adicional,
        );

        $esArriendo = $tipo === TipoOperacion::Arriendo;

        return [
            'tenant_id' => $oferta->tenant_id,
            'oferta_id' => $oferta->id,
            'propiedad_id' => $oferta->propiedad_id,
            'cliente_id' => $cliente->id,
            'corredor_id' => $corredorId,

            'tipo' => $esArriendo ? 'arriendo' : 'reserva',

            'valor_pactado' => $valorPactado,
            'valor_lista_oferta' => $oferta->valor_lista,
            'porcentaje_comision' => $porcentaje,
            'comision_monto' => $comision,
            'modalidad_comision' => $adicional > 0 ? 'porcentaje_mas_fijo' : 'porcentaje',
            'comision_adicional' => $adicional > 0 ? $adicional : null,

            'creada_en' => $this->fechaActual(),

            'expira_en' => $esArriendo ? null : $this->expiraEn(),

            'fecha_inicio' => $esArriendo ? $this->input('fecha_inicio') : null,
            'fecha_termino' => $esArriendo ? $this->input('fecha_termino') : null,

            'renovaciones' => 0,
            'estado_contrato' => EstadoContrato::Vigente->value,
        ];
    }
}