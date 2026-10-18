<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EstadoComercial;
use App\Enums\TipoOperacion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Estado comercializable de una oferta.
 *
 * Es configuracion de la inmobiliaria, no un enum de PHP: ver la migracion
 * 2026_02_10_000002. `clave_sistema` es lo unico que el motor lee, y es lo que
 * permite que una inmobiliaria llame "en oferta" a lo que Hefesto llama
 * disponible sin que la liberacion automatica se rompa.
 */
class Estado extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'estados';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'nombre',
        'descripcion',
        'clave_sistema',
        'tipo_operacion',
        'es_inicial',
        'es_final',
        'posicion',
        'color',
        'activo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo_operacion' => 'string',
            'es_inicial' => 'boolean',
            'es_final' => 'boolean',
            'posicion' => 'integer',
            'activo' => 'boolean',
        ];
    }

    /** @return HasMany<Oferta, $this> */
    public function ofertas(): HasMany
    {
        return $this->hasMany(Oferta::class, 'estado_id');
    }

    /**
     * Si este estado corresponde a uno que el motor reconoce.
     *
     * Se resuelve el enum en tiempo de ejecucion y no como tipo de la columna,
     * porque puede ser NULL para los estados que son solo configuracion.
     */
    public function claveSistema(): ?EstadoComercial
    {
        return $this->clave_sistema === null
            ? null
            : EstadoComercial::tryFrom($this->clave_sistema);
    }

    /**
     * Este estado aplica a un tipo de operacion?
     *
     * 'ambas' aplica a los dos, y es lo que permite que exista un estado
     * "retirada del mercado" compartido por venta y arriendo.
     */
    public function aplicaA(TipoOperacion $tipo): bool
    {
        return $this->tipo_operacion === 'ambas' || $this->tipo_operacion === $tipo->value;
    }
}