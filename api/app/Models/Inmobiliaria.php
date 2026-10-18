<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Inmobiliaria: el tenant.
 *
 * No lleva Row Level Security. Se consulta en el login, antes de que exista
 * sesion y por lo tanto antes de que exista `app.tenant_id`. Ver la migracion
 * 2026_02_10_000013.
 */
class Inmobiliaria extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'inmobiliarias';

    /** @var list<string> */
    protected $fillable = [
        'id',
        'correlacion_identidad',
        'slug',
        'nombre',
        'razon_social',
        'rut',
        'direccion',
        'telefono',
        'correo',
        'zona_horaria',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    /** @return HasMany<Usuario, $this> */
    public function usuarios(): HasMany
    {
        return $this->hasMany(Usuario::class, 'tenant_id');
    }

    /** @return HasMany<Estado, $this> */
    public function estados(): HasMany
    {
        return $this->hasMany(Estado::class, 'tenant_id');
    }

    /** @return HasMany<Propiedad, $this> */
    public function propiedades(): HasMany
    {
        return $this->hasMany(Propiedad::class, 'tenant_id');
    }

    /** @return HasManyThrough<Propiedad, Oferta, $this> */
    public function propiedadesEnOferta(): HasManyThrough
    {
        return $this->hasManyThrough(
            Propiedad::class,
            Oferta::class,
            'tenant_id',
            'id',
            'id',
            'propiedad_id',
        );
    }
}