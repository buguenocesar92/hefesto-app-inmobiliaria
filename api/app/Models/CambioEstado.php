<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un tramo de la historia de una oferta: cuanto tiempo estuvo en un estado.
 *
 * SOLO AGREGAR. Esta tabla no se actualiza para "corregir" nada y no se borra.
 * La unica actualizacion que admite es la que hace
 * App\Services\Estados\RegistradorDeCambioEstado al cerrar un tramo abierto,
 * escribiendo `hasta` y `duracion_dias`.
 *
 * `hasta IS NULL` significa "el estado actual". Esa convencion es la que hace
 * que la linea de tiempo se lea sin logica: no hay que buscar el maximo ni
 * reconstruir la secuencia.
 */
class CambioEstado extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'cambios_estado';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'oferta_id',
        'estado_anterior_id',
        'estado_nuevo_id',
        'desde',
        'hasta',
        'duracion_dias',
        'usuario_id',
        'motivo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'desde' => 'datetime',
            'hasta' => 'datetime',
            'duracion_dias' => 'integer',
        ];
    }

    /** @return BelongsTo<Oferta, $this> */
    public function oferta(): BelongsTo
    {
        return $this->belongsTo(Oferta::class);
    }

    /** @return BelongsTo<Estado, $this> */
    public function estadoNuevo(): BelongsTo
    {
        return $this->belongsTo(Estado::class, 'estado_nuevo_id');
    }

    /** @return BelongsTo<Estado, $this> */
    public function estadoAnterior(): BelongsTo
    {
        return $this->belongsTo(Estado::class, 'estado_anterior_id');
    }

    /** @return BelongsTo<Usuario, $this> */
    public function usuario(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    /**
     * Si este tramo sigue abierto, o sea, es el estado actual de la oferta.
     */
    public function estaAbierto(): bool
    {
        return $this->hasta === null;
    }

    /**
     * Duracion en dias. Para un tramo abierto, el tiempo transcurrido hasta
     * hoy, que no es lo mismo que la duracion final.
     */
    public function dias(): int
    {
        if ($this->duracion_dias !== null) {
            return $this->duracion_dias;
        }

        return $this->desde === null
            ? 0
            : max(0, (int) $this->desde->diffInDays(Carbon::now()));
    }

    /**
     * Este cambio lo hizo el sistema y no una persona.
     *
     * Una liberacion automatica de reserva vencida no tiene responsable, y
     * mostrar el nombre de un corredor seria mentir.
     */
    public function esAutomatico(): bool
    {
        return $this->usuario_id === null;
    }
}