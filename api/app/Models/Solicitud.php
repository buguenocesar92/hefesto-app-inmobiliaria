<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoOperacion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Solicitud: lo que UN cliente pide.
 *
 * No lleva `propiedad_id`, y esa ausencia es deliberada: una solicitud puede
 * requerir varias propiedades y una propiedad puede servir para varias
 * solicitudes. La union esta en `solicitud_propiedad`.
 */
class Solicitud extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'solicitudes';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'cliente_id',
        'corredor_id',
        'tipo_operacion',
        'zona',
        'comuna',
        'presupuesto_min',
        'presupuesto_max',
        'dormitorios_min',
        'banos_min',
        'superficie_min_m2',
        'estado',
        'notas',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo_operacion' => TipoOperacion::class,
            'presupuesto_min' => 'integer',
            'presupuesto_max' => 'integer',
            'dormitorios_min' => 'integer',
            'banos_min' => 'integer',
            'superficie_min_m2' => 'decimal:2',
        ];
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

    /**
     * Las propiedades que se propusieron para esta solicitud.
     *
     * @return BelongsToMany<Propiedad, $this>
     */
    public function propiedades(): BelongsToMany
    {
        return $this->belongsToMany(
            Propiedad::class,
            'solicitud_propiedad',
            'solicitud_id',
            'propiedad_id',
        )->withPivot(['id', 'usuario_id', 'propuesta_en', 'estado', 'motivo_descarte'])
          ->withTimestamps();
    }

    public function estaActiva(): bool
    {
        return $this->estado === 'activa';
    }
}