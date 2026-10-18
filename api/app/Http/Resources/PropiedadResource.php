<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una propiedad con el estado de sus ofertas.
 *
 * POR QUE VAN LAS OFERTAS ADENTRO DE LA FICHA
 * -------------------------------------------
 * Porque es la unidad de informacion que el usuario realmente lee: el
 * departamento de la Avenida Vespucio, en venta y en arriendo, y hace cuanto
 * lleva en cada uno. Separarlo en dos llamadas obliga a la interfaz a
 * emparejar por id y a mostrar un estado en blanco mientras llega la segunda.
 *
 * `estado_venta` y `estado_arriendo` van ennull cuando la propiedad no esta en
 * ese mercado. No es un dato faltante: es la respuesta a "esta en venta?", y
 * por eso se distingue de "esta en venta y no sabemos en que estado".
 *
 * @mixin \App\Models\Propiedad
 */
class PropiedadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $peticion): array
    {
        return [
            'id' => $this->id,
            'codigo_interno' => $this->codigo_interno,
            'direccion' => $this->direccion,
            'comuna' => $this->comuna,
            'region' => $this->region,
            'tipo_propiedad' => $this->tipo_propiedad,

            'caracteristicas' => [
                'dormitorios' => $this->dormitorios,
                'banos' => $this->banos,
                'estacionamientos' => $this->estacionamientos,
                'superficie_m2' => $this->superficie_m2,
                'anio_construccion' => $this->anio_construccion,
                'piso' => $this->piso,
                'departamento' => $this->departamento,
                'amoblado' => $this->amoblado,
            ],

            // Precios de LISTA. No el pactado, que no se expone aca.
            'valor_venta' => $this->valor_venta,
            'valor_arriendo' => $this->valor_arriendo,

            'descripcion' => $this->descripcion,
            'activa' => $this->activa,

            'corredor' => $this->corredor === null ? null : [
                'id' => $this->corredor->id,
                'nombre' => $this->corredor->nombre,
            ],

            'estado_venta' => $this->estadoDe(\App\Enums\TipoOperacion::Venta),
            'estado_arriendo' => $this->estadoDe(\App\Enums\TipoOperacion::Arriendo),

            'fotos' => $this->whenLoaded('fotos', fn () => $this->fotos->map(fn ($foto): array => [
                'id' => $foto->id,
                'ruta' => $foto->ruta,
                'descripcion' => $foto->descripcion,
                'es_portada' => $foto->es_portada,
            ])->values()),

            'visitas' => $this->whenLoaded('visitas', fn () => $this->visitas->count()),
        ];
    }

    /**
     * El estado de una de las dos ofertas, o null si no existe.
     *
     * @return array<string, mixed>|null
     */
    private function estadoDe(\App\Enums\TipoOperacion $tipo): ?array
    {
        $oferta = $this->ofertas->firstWhere('tipo_operacion', $tipo);

        if ($oferta === null) {
            return null;
        }

        return [
            'oferta_id' => $oferta->id,
            'estado_id' => $oferta->estado_id,
            'estado_nombre' => $oferta->estado?->nombre,
            'estado_color' => $oferta->estado?->color,
            'estado_desde' => $oferta->estado_desde?->toIso8601String(),

            // El numero que hace de portada del producto.
            'dias_en_estado' => $oferta->diasEnEstado(),

            'valor_lista' => $oferta->valor_lista,
            'visible_en_portal' => $oferta->visible_en_portal,
        ];
    }
}