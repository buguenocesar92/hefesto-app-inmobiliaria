<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoOperacion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Oferta: la presencia comercial de una propiedad en un tipo de operacion.
 *
 * `estado_id` y `estado_desde` son una COPIA del ultimo tramo abierto en
 * `cambios_estado`. La verdad esta ahi; aca esta el resumen para poder listar y
 * filtrar con indice. Ver la migracion 2026_02_10_000005 para por que se
 * duplica y como se verifica que no diverja.
 */
class Oferta extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'ofertas';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'propiedad_id',
        'corredor_id',
        'tipo_operacion',
        'estado_id',
        'estado_desde',
        'valor_lista',
        'visible_en_portal',
        'activa',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo_operacion' => TipoOperacion::class,
            'estado_desde' => 'datetime',
            'valor_lista' => 'integer',
            'visible_en_portal' => 'boolean',
            'activa' => 'boolean',
        ];
    }

    /** @return BelongsTo<Propiedad, $this> */
    public function propiedad(): BelongsTo
    {
        return $this->belongsTo(Propiedad::class);
    }

    /** @return BelongsTo<Estado, $this> */
    public function estado(): BelongsTo
    {
        return $this->belongsTo(Estado::class, 'estado_id');
    }

    /** @return BelongsTo<Usuario, $this> */
    public function corredor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'corredor_id');
    }

    /**
     * El historial completo, del tramo mas reciente al mas antiguo.
     *
     * @return HasMany<CambioEstado, $this>
     */
    public function cambiosEstado(): HasMany
    {
        return $this->hasMany(CambioEstado::class, 'oferta_id')->orderByDesc('desde');
    }

    /**
     * Solo el tramo abierto: el estado en que esta la oferta ahora.
     *
     * @return HasMany<CambioEstado, $this>
     */
    public function tramoAbierto(): HasMany
    {
        return $this->hasMany(CambioEstado::class, 'oferta_id')->whereNull('hasta');
    }

    /** @return HasMany<Operacion, $this> */
    public function operaciones(): HasMany
    {
        return $this->hasMany(Operacion::class, 'oferta_id');
    }

    /**
     * Dias que lleva la oferta en su estado actual.
     */
    public function diasEnEstado(): int
    {
        return $this->estado_desde === null
            ? 0
            : max(0, (int) $this->estado_desde->diffInDays(Carbon::now()));
    }
}