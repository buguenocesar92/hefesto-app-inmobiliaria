<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoContrato;
use App\Enums\TipoOperacion;
use App\Services\Operaciones\CalculadoraDeComision;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Operacion: una reserva de venta o un arriendo.
 *
 * UNA TABLA, DOS NEGOCIOS
 * -----------------------
 * Reserva y arriendo estan juntos porque comparten casi todo: cliente, corredor,
 * oferta que cierran, comision sobre el pactado y un estado de contrato. Lo que
 * los diferencia son las fechas, y esas fechas ya estan separadas por columna y
 * por una restriccion que no deja mezclarlas:
 *
 *     reserva  -> tiene `expira_en`, NO tiene fechas de contrato
 *     arriendo -> NO tiene `expira_en`, tiene `fecha_inicio` y `fecha_termino`
 *
 * Esa restriccion esta en la base (chk_operaciones_vencimiento_por_tipo), no en
 * el codigo de la aplicacion. Por eso una reserva sin fecha de vencimiento no
 * se puede guardar ni por error: es la condicion que hace que la liberacion
 * automatica tenga algo que mirar.
 *
 * `valor_pactado` es el unico valor del que sale la comision. Ni `valor_lista`
 * de la oferta ni `valor_venta` de la ficha. Ver CalculadoraDeComision.
 */
class Operacion extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'operaciones';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'oferta_id',
        'propiedad_id',
        'cliente_id',
        'corredor_id',
        'tipo',
        'valor_pactado',
        'valor_lista_oferta',
        'porcentaje_comision',
        'comision_monto',
        'modalidad_comision',
        'comision_adicional',
        'creada_en',
        'expira_en',
        'fecha_inicio',
        'fecha_termino',
        'renovaciones',
        'estado_contrato',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => 'string',
            'valor_pactado' => 'integer',
            'valor_lista_oferta' => 'integer',
            'porcentaje_comision' => 'decimal:2',
            'comision_monto' => 'integer',
            'comision_adicional' => 'integer',
            'creada_en' => 'datetime',
            'expira_en' => 'datetime',
            'fecha_inicio' => 'date',
            'fecha_termino' => 'date',
            'renovaciones' => 'integer',
            'estado_contrato' => EstadoContrato::class,
        ];
    }

    /** @return BelongsTo<Oferta, $this> */
    public function oferta(): BelongsTo
    {
        return $this->belongsTo(Oferta::class);
    }

    /** @return BelongsTo<Propiedad, $this> */
    public function propiedad(): BelongsTo
    {
        return $this->belongsTo(Propiedad::class);
    }

    /** @return BelongsTo<Cliente, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /** @return BelongsTo<Usuario, $this> */
    public function corredor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'corredor_id');
    }

    public function esReserva(): bool
    {
        return $this->tipo === 'reserva';
    }

    public function esArriendo(): bool
    {
        return $this->tipo === 'arriendo';
    }

    /**
     * Quanto le falta para vencer, en dias. Negativo si ya vencio.
     *
     * Es el numero que se muestra en rojo en la ficha de la reserva.
     */
    public function diasParaVencer(): ?int
    {
        if ($this->expira_en === null) {
            return null;
        }

        return (int) $this->expira_en->diffInDays(now(), false);
    }

    /**
     * Dias que falta para que termine el arriendo.
     */
    public function diasParaTerminar(): ?int
    {
        if ($this->fecha_termino === null) {
            return null;
        }

        return (int) now()->diffInDays($this->fecha_termino->endOfDay(), false);
    }

    /**
     * Calcula la comision con la regla unica del sistema y la deja escrita.
     *
     * Se expone como metodo del modelo para que el alta de una operacion sea una
     * linea y no una cuenta suelta en el controlador. La regla vive en
     * CalculadoraDeComision; aca solo se aplica.
     */
    public function calcularComision(float $porcentajeComision, int $adicional = 0): void
    {
        $this->porcentaje_comision = $porcentajeComision;
        $this->comision_adicional = $adicional > 0 ? $adicional : null;
        $this->comision_monto = CalculadoraDeComision::calcularConAdicional(
            valorPactado: (int) $this->valor_pactado,
            porcentajeComision: $porcentajeComision,
            adicional: $adicional,
        );
    }
}