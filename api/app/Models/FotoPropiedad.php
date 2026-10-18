<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foto de la propiedad.
 *
 * `ruta` es RELATIVA al volumen de almacenamiento. Nunca absoluta, nunca con
 * `..`: es lo que permite montar el volumen en otro servidor sin romper lo ya
 * guardado, y lo que hace posible comprobar que una ruta no sale del volumen
 * antes de servir el archivo. Ver docs/07.
 */
class FotoPropiedad extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'fotos_propiedad';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'propiedad_id',
        'ruta',
        'descripcion',
        'orden',
        'es_portada',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'orden' => 'integer',
            'es_portada' => 'boolean',
        ];
    }

    /** @return BelongsTo<Propiedad, $this> */
    public function propiedad(): BelongsTo
    {
        return $this->belongsTo(Propiedad::class);
    }

    /**
     * Comprueba que la ruta no intenta salir del volumen.
     *
     * La policy aplica esta comprobacion antes de servir el archivo. Una ruta
     * con `..` o absoluta se rechaza con 404, no con un error de servidor: no se
     * le confirma a quien pregunta que la propiedad existe.
     */
    public function rutaEsSegura(): bool
    {
        if ($this->ruta === '') {
            return false;
        }

        // Ruta absoluta de Unix o de Windows.
        if (str_starts_with($this->ruta, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $this->ruta) === 1) {
            return false;
        }

        // Cualquier segmento `..`, en cualquier separador.
        $normalizada = str_replace('\\', '/', $this->ruta);

        foreach (explode('/', $normalizada) as $segmento) {
            if ($segmento === '..') {
                return false;
            }
        }

        return true;
    }
}