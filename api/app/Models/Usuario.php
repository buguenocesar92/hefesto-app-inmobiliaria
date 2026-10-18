<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Rol;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Usuario de la inmobiliaria: un corredor o un administrador.
 *
 * El corredor es a la vez la persona que entra y la cartera que lleva. No hay
 * una tabla `corredores` separada, y el motivo esta en la migracion de
 * `usuarios`.
 */
class Usuario extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'usuarios';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'correlacion_identidad',
        'nombre',
        'correo',
        'rol',
        'activo',
        'ultimo_ingreso',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rol' => Rol::class,
            'activo' => 'boolean',
            'ultimo_ingreso' => 'datetime',
        ];
    }

    /** @return BelongsTo<Inmobiliaria, $this> */
    public function inmobiliaria(): BelongsTo
    {
        return $this->belongsTo(Inmobiliaria::class, 'tenant_id');
    }

    /** @return HasMany<Propiedad, $this> */
    public function propiedades(): HasMany
    {
        return $this->hasMany(Propiedad::class, 'corredor_id');
    }

    /** @return HasMany<Cliente, $this> */
    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class, 'corredor_id');
    }

    /**
     * El usuario es el portador de `tenant_id` para el middleware: por eso no
     * es nullable en la sesion aunque si lo sea en la tabla (una cuenta
     * creada desde el proveedor y todavia no asignada).
     */
    public function esAdministrador(): bool
    {
        return $this->rol->esAdministrador();
    }
}