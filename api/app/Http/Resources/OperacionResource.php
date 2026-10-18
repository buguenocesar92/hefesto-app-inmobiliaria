<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una operacion: reserva o arriendo.
 *
 * LA SEPARACION DEL DINERO
 * -----------------------
 * `valor_pactado` y `comision_monto` salen en NULL cuando quien pregunta es un
 * corredor. No es un filtro hecho en la vista: es la policy
 * `OperacionPolicy::verDinero`, que responde solo el administrador.
 *
 * Se hace asi, y no con un 403 parcial o un recurso distinto, para que el
 * corredor reciba una respuesta bien formada con los campos sensibles en null.
 * Un 403 sobre un recurso que ya se esta mostrando obliga al cliente a tratar un
 * caso especial, y los casos especiales son donde aparecen las fugas.
 *
 * @mixin \App\Models\Operacion
 */
class OperacionResource extends JsonResource
{
    /**
     * Marca la peticion ya autorizada por `verDinero`, para que el Resource no
     * tenga que repetir la comprobacion con una consulta de mas.
     */
    public bool $veDinero = false;

    /** @return array<string, mixed> */
    public function toArray(Request $peticion): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'estado_contrato' => $this->estado_contrato->value,

            'oferta_id' => $this->oferta_id,
            'propiedad_id' => $this->propiedad_id,
            'cliente_id' => $this->cliente_id,

            'propiedad' => $this->whenLoaded('propiedad', fn () => [
                'id' => $this->propiedad->id,
                'direccion' => $this->propiedad->direccion,
                'comuna' => $this->propiedad->comuna,
            ]),

            'cliente' => $this->whenLoaded('cliente', fn () => [
                'id' => $this->cliente->id,
                'nombre' => $this->cliente->nombreCompleto(),
            ]),

            'corredor' => $this->whenLoaded('corredor', fn () => $this->corredor === null ? null : [
                'id' => $this->corredor->id,
                'nombre' => $this->corredor->nombre,
            ]),

            // ------------------------------------------------------------------
            // Dinero
            // ------------------------------------------------------------------
            'valor_pactado' => $this->veDinero ? $this->valor_pactado : null,
            'valor_lista_oferta' => $this->veDinero ? $this->valor_lista_oferta : null,
            'porcentaje_comision' => $this->veDinero ? (float) $this->porcentaje_comision : null,
            'comision_monto' => $this->veDinero ? $this->comision_monto : null,

            // El precio de lista SI se muestra siempre: esta en la ficha y no
            // es informacion sensible. Sin el, el corredor no puede comparar su
            // operacion con lo que esta en el mercado.
            'valor_lista_propiedad' => $this->whenLoaded('propiedad', fn () => $this->propiedad->valor_venta
                ?? $this->propiedad->valor_arriendo),

            // ------------------------------------------------------------------
            // Fechas
            // ------------------------------------------------------------------
            'creada_en' => $this->creada_en?->toIso8601String(),
            'expira_en' => $this->expira_en?->toIso8601String(),
            'dias_para_vencer' => $this->diasParaVencer(),
            'fecha_inicio' => $this->fecha_inicio?->toDateString(),
            'fecha_termino' => $this->fecha_termino?->toDateString(),
            'dias_para_terminar' => $this->diasParaTerminar(),
            'renovaciones' => $this->renovaciones,
        ];
    }
}