<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un tramo del historial de estados.
 *
 * `dias` sale del metodo `dias()` del modelo y no del atributo guardado, para
 * que un tramo abierto devuelva el tiempo transcurrido hasta hoy y uno cerrado
 * devuelva su duracion final. La interfaz no tiene que hacer esa diferencia.
 *
 * `es_automatico` viaja explicito: la linea de tiempo tiene que poder decir
 * "liberada por vencimiento" sin suponer que fue decision de un corredor, y no
 * puede deducirlo de que `usuario` venga en null.
 *
 * @mixin \App\Models\CambioEstado
 */
class CambioEstadoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $peticion): array
    {
        return [
            'id' => $this->id,
            'oferta_id' => $this->oferta_id,

            'estado_anterior' => $this->estadoAnterior === null ? null : [
                'id' => $this->estadoAnterior->id,
                'nombre' => $this->estadoAnterior->nombre,
                'color' => $this->estadoAnterior->color,
            ],

            'estado_nuevo' => [
                'id' => $this->estadoNuevo->id,
                'nombre' => $this->estadoNuevo->nombre,
                'color' => $this->estadoNuevo->color,
            ],

            'desde' => $this->desde?->toIso8601String(),
            'hasta' => $this->hasta?->toIso8601String(),

            // Duracion final si el tramo esta cerrado, tiempo transcurrido si
            // sigue abierto.
            'dias' => $this->dias(),

            'duracion_final' => $this->duracion_dias,

            'abierto' => $this->estaAbierto(),
            'es_automatico' => $this->esAutomatico(),

            // Para un tramo automatico se expone `null` y no un nombre inventado.
            'usuario' => $this->usuario === null ? null : [
                'id' => $this->usuario->id,
                'nombre' => $this->usuario->nombre,
            ],

            'motivo' => $this->motivo,
        ];
    }
}