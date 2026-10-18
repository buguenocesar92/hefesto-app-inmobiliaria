<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoOperacion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Propiedad: el inmueble, independiente de la oferta.
 *
 * NO tiene estado. El estado vive en `ofertas`, y una propiedad tiene una oferta
 * por cada tipo de operacion que tenga abierta. Es lo que permite que un mismo
 * departamento este en venta y en arriendo a la vez, cada uno con su propia
 * linea de tiempo.
 */
class Propiedad extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'propiedades';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'corredor_id',
        'codigo_interno',
        'direccion',
        'comuna',
        'region',
        'tipo_propiedad',
        'dormitorios',
        'banos',
        'estacionamientos',
        'superficie_m2',
        'anio_construccion',
        'piso',
        'departamento',
        'amoblado',
        'descripcion',
        'valor_venta',
        'valor_arriendo',
        'propietario_nombre',
        'propietario_telefono',
        'propietario_correo',
        'activa',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dormitorios' => 'integer',
            'banos' => 'integer',
            'estacionamientos' => 'integer',
            'superficie_m2' => 'decimal:2',
            'anio_construccion' => 'integer',
            'amoblado' => 'boolean',
            'valor_venta' => 'integer',
            'valor_arriendo' => 'integer',
            'activa' => 'boolean',
        ];
    }

    /** @return BelongsTo<Usuario, $this> */
    public function corredor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'corredor_id');
    }

    /**
     * @return HasMany<Oferta, $this>
     */
    public function ofertas(): HasMany
    {
        return $this->hasMany(Oferta::class);
    }

    /**
     * La oferta de un tipo de operacion, o null si la propiedad no esta en ese
     * mercado. Que sea null es un dato valido y no un error: es lo que
     * distingue "disponible en arriendo" de "en arriendo y en venta".
     */
    public function oferta(TipoOperacion $tipo): ?Oferta
    {
        return $this->ofertas->firstWhere('tipo_operacion', $tipo);
    }

    /** @return HasMany<FotoPropiedad, $this> */
    public function fotos(): HasMany
    {
        return $this->hasMany(FotoPropiedad::class, 'propiedad_id')->orderBy('orden');
    }

    /** @return HasMany<Visita, $this> */
    public function visitas(): HasMany
    {
        return $this->hasMany(Visita::class, 'propiedad_id');
    }

    /**
     * La relacion de varios a varios con las solicitudes, a traves de una tabla
     * que guarda mas que dos ids.
     *
     * @see solicitud_propiedad
     */
    public function solicitudes(): BelongsToMany
    {
        return $this->belongsToMany(
            Solicitud::class,
            'solicitud_propiedad',
            'propiedad_id',
            'solicitud_id',
        )->withPivot(['id', 'usuario_id', 'propuesta_en', 'estado', 'motivo_descarte'])
          ->withTimestamps();
    }
}