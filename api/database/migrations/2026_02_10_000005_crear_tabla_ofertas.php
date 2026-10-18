<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ofertas: la presencia comercial de una propiedad en un tipo de operacion.
 *
 * EL CORAZON DEL MODELO
 * --------------------
 * Una inmobiliaria no vende: ofrece. Y una oferta no esta "vendida": esta en un
 * estado, y lo que cuesta plata es CUANTO TIEMPO lleva en ese estado. Por eso
 * la oferta es una entidad propia, y no un par de campos en `propiedades`.
 *
 * Una propiedad tiene como maximo una oferta por tipo de operacion
 * (`uq_ofertas_propiedad_tipo`). Con eso, un departamento puede estar en venta
 * y en arriendo al mismo tiempo, cada uno con su propio estado y su propia
 * linea de tiempo:
 *
 *   Propiedad 1458
 *     - Oferta VENTA   -> disponible desde 2026-01-12, 214 dias
 *     - Oferta ARRIENDO-> arrendado desde 2026-03-01, 156 dias
 *
 * `estado_id` y `estado_desde` son una COPIA desnormalizada del ultimo cambio,
 * mantenida por App\Services\Estados\RegistradorDeCambioEstado dentro de la
 * misma transaccion que escribe `cambios_estado`.
 *
 * POR QUE LA COPIA ES SEGURA
 * --------------------------
 * Podria parecer que la verdad esta en `cambios_estado` y que este par de
 * columnas se va a desincronizar. La razon para duplicarlos es concreta: el
 * tablero del producto tiene que responder "cuantas propiedades hay en estado
 * reservada" sobre decenas de miles de filas y con indice. Calcularlo con
 * `MAX(fecha)` por oferta seria un agrupado sobre toda la tabla en cada carga.
 *
 * La desincronizacion no se acepta en silencio: la prueba
 * `CoherenciaDelHistorialTest` compara las dos copias y falla si divergen.
 * Si la copia es lo que se usa para mostrar, tiene que ser la que se verifica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ofertas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            $tabla->uuid('propiedad_id');

            // El corredor que lleva ESTA oferta. Puede no ser el mismo que el
            // de la propiedad: la cartera se puede traspasar sin que cambie el
            // historial ya registrado.
            $tabla->uuid('corredor_id')->nullable();

            $tabla->string('tipo_operacion', 10);

            // COPIA desnormalizada del estado actual. La verdad esta en
            // `cambios_estado`.
            $tabla->uuid('estado_id');
            $tabla->timestampTz('estado_desde');

            // Precio de lista de ESTA oferta. Puede diferir del valor de la
            // propiedad: el dueño puede pedir más para la venta y menos para
            // el arriendo. Sigue siendo precio de LISTA.
            $tabla->bigInteger('valor_lista')->nullable();

            $tabla->boolean('visible_en_portal')->default(true);
            $tabla->boolean('activa')->default(true);

            $tabla->timestampsTz();

            // Una sola oferta por propiedad y tipo de operacion. Esta es la
            // restriccion que hace que "venta y arriendo a la vez" sea posible
            // sin que una pise a la otra.
            $tabla->unique(['propiedad_id', 'tipo_operacion'], 'uq_ofertas_propiedad_tipo');

            $tabla->index(['tenant_id', 'tipo_operacion', 'estado_id'], 'idx_ofertas_tenant_tipo_estado');
            $tabla->index(['tenant_id', 'corredor_id']);
        });

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_ofertas_tipo_operacion
            CHECK (tipo_operacion IN ('venta', 'arriendo'))
            SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_ofertas_valor_lista
            CHECK (valor_lista IS NULL OR valor_lista >= 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ofertas');
    }
};