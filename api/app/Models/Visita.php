<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Visita: una persona vio una propiedad en un momento, y quedo registrado.
 *
 * El campo `resultado` es lo que hace util el registro. Una visita agendada que
 * nadie realizo es informacion distinta de una visita con el cliente que quedo
 * "indeciso", y el informe de permanencia lo necesita: la razon de que una
 * reserva se caiga casi nunca es el precio, es que nadie fue a mirar.
 */
class Visita extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'visitas';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'propiedad_id',
        'cliente_id',
        'corredor_id',
        'inicio',
        'duracion_minutos',
        'estado',
        'resultado',
        'notas',
        'ofrecio_ajuste',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'inicio' => 'datetime',
            'duracion_minutos' => 'integer',
            'ofrecio_ajuste' => 'boolean',
        ];
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

    public function estaRealizada(): bool
    {
        return $this->estado === 'realizada';
    }
}