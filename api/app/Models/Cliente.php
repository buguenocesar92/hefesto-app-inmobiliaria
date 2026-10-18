<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cliente: el solicitante.
 *
 * Datos personales bajo la Ley 19.628. Ver docs/07.
 *
 * Un cliente tiene VARIAS solicitudes activas a la vez, y por eso la relacion
 * con las propiedades no se resuelve metiendo una columna en ninguna de las dos
 * tablas. Ver la migracion de `solicitudes`.
 */
class Cliente extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'clientes';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'corredor_id',
        'nombres',
        'apellidos',
        'telefono',
        'correo',
        'notas',
        'consentimiento_contacto',
        'consentimiento_fecha',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'consentimiento_contacto' => 'boolean',
            'consentimiento_fecha' => 'datetime',
            'activo' => 'boolean',
        ];
    }

    /** @return BelongsTo<Usuario, $this> */
    public function corredor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'corredor_id');
    }

    /** @return HasMany<Solicitud, $this> */
    public function solicitudes(): HasMany
    {
        return $this->hasMany(Solicitud::class, 'cliente_id');
    }

    /**
     * Solo las solicitudes que siguen searching. Es la vista por defecto: un
     * cliente con cinco solicitudes, cuatro ya cerradas y una viva, tiene que
     * aparecer con una.
     *
     * @return HasMany<Solicitud, $this>
     */
    public function solicitudesActivas(): HasMany
    {
        return $this->hasMany(Solicitud::class, 'cliente_id')->where('estado', 'activa');
    }

    /** @return HasMany<Visita, $this> */
    public function visitas(): HasMany
    {
        return $this->hasMany(Visita::class, 'cliente_id');
    }

    /** @return HasMany<Operacion, $this> */
    public function operaciones(): HasMany
    {
        return $this->hasMany(Operacion::class, 'cliente_id');
    }

    /**
     * Para llegar a las propiedades propuestas hay que pasar por las solicitudes:
     *
     *     $cliente->solicitudes()->with('propiedades')->get()
     *
     * No hay un vinculo directo Cliente -> Propiedad a proposito. La tabla
     * `solicitud_propiedad` no tiene `cliente_id`, asi que un `belongsToMany`
     * directo no tendria contra que columna emparejarse y devolveria
     * propiedades sin relacion con este cliente. La relacion de varios a varios
     * real, la unica que tiene datos de union, se expresa desde Solicitud.
     *
     * @see \App\Models\Solicitud::propiedades()
     */
    public function nombreCompleto(): string
    {
        return trim("{$this->nombres} {$this->apellidos}");
    }
}