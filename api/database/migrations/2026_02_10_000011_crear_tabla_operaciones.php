<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operaciones: la reserva de una venta y el arriendo de un alquiler.
 *
 * Es la tabla donde el negocio se vuelve real: aqui aparece el valor pactado,
 * que es sobre el que se calcula la comision, y las fechas que hacen que una
 * reserva caduque y que un arriendo termine.
 *
 * VALOR PACTADO, NO VALOR DE FICHA
 * -------------------------------
 * `valor_pactado` es el precio efectivamente acordado y `valor_lista_oferta` es
 * una foto del precio de lista del momento en que se cerro. Guardar los dos no
 * es redundancia: es lo que permite responder "los corridores cierran 6% bajo la
 * lista". Y la comision se calcula SIEMPRE sobre `valor_pactado`:
 *
 *     comision_monto = round(valor_pactado * porcentaje_comision / 100)
 *
 * Esta regla esta escrita en un solo lugar, en
 * App\Services\Operaciones\CalculadoraDeComision, y hay una prueba que la fija.
 * Calcular la comision sobre `valor_lista` es el error clasico de este rubro, y
 * por eso el calculo no esta disperso en los controladores.
 *
 * LA RESERVA TIENE FECHA DE VENCIMIENTO
 * ------------------------------------
 * `expira_en` es lo que hace que este sistema no pierda ventas por inercia. Si
 * nadie confirma antes de esa fecha, la propiedad tiene que volver al mercado
 * sola. Ese retorno lo hace `LiberarReservasVencidas` (el comando diario y el
 * trabajo en cola), y queda anotado en `cambios_estado` como cambio automatico.
 *
 * SI NO SE LLENA, NO APLICA
 * -------------------------
 * `expira_en` es obligatorio cuando `tipo` es 'reserva' y prohibido cuando es
 * 'arriendo'. Un arriendo no "expira": termina, y su renovacion se trata con
 * `fecha_termino` y `renovaciones`. La restriccion lo hace obligatorio en la
 * base, no por convencion del programador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operaciones', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            $tabla->uuid('oferta_id');
            $tabla->uuid('propiedad_id');
            $tabla->uuid('cliente_id');
            $tabla->uuid('corredor_id')->nullable();

            $tabla->string('tipo', 20);

            // ------------------------------------------------------------------
            // Dinero
            // ------------------------------------------------------------------
            $tabla->bigInteger('valor_pactado');
            $tabla->bigInteger('valor_lista_oferta')->nullable();
            $tabla->decimal('porcentaje_comision', 5, 2);
            $tabla->bigInteger('comision_monto');

            // En arriendo, las comisiones suelen pactarse como "un mes de
            // arriendo". Va aparte para no tener que modelar el arriendo como
            // si fuera una venta.
            $tabla->string('modalidad_comision', 30)->default('porcentaje');
            $tabla->bigInteger('comision_adicional')->nullable();

            // ------------------------------------------------------------------
            // Fechas
            // ------------------------------------------------------------------
            $tabla->timestampTz('creada_en');

            // Solo para `tipo = 'reserva'`.
            $tabla->timestampTz('expira_en')->nullable();

            // Solo para `tipo = 'arriendo'`.
            $tabla->date('fecha_inicio')->nullable();
            $tabla->date('fecha_termino')->nullable();

            // Cuantas veces se ha renovado. Un arriendo con renovaciones = 2 no
            // es el mismo negocio que uno con renovaciones = 0.
            $tabla->integer('renovaciones')->default(0);

            $tabla->string('estado_contrato', 30)->default('vigente');

            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'tipo', 'estado_contrato'], 'idx_operaciones_tenant_tipo_estado');

            // El indice que usa el trabajo de liberacion: solo mira reservas
            // vigentes que ya vencieron.
            $tabla->index(['expira_en'], 'idx_operaciones_vencimiento');

            // Para el aviso de renovacion: arriendos que terminan pronto.
            $tabla->index(['tenant_id', 'fecha_termino'], 'idx_operaciones_vencimiento_arriendo');
        });

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_operaciones_tipo
            CHECK (tipo IN ('reserva', 'arriendo'))
            SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_operaciones_estado_contrato
            CHECK (estado_contrato IN (
                'vigente', 'expirada', 'confirmada', 'anulada',
                'por_renovar', 'renovada', 'retornado', 'terminada'
            ))
            SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_operaciones_comision
            CHECK (
                valor_pactado > 0
                AND porcentaje_comision > 0
                AND porcentaje_comision <= 100
                AND comision_monto > 0
                AND (comision_adicional IS NULL OR comision_adicional >= 0)
            )
            SQL);

        // La fecha de vencimiento es lo que existe o no existe segun el tipo.
        // Esta restriccion es la que impide que un arriendo tenga `expira_en`
        // (no caduca) o que una reserva no lo tenga (nunca vuelve al mercado).
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_operaciones_vencimiento_por_tipo
            CHECK (
                (tipo = 'reserva' AND expira_en IS NOT NULL
                 AND fecha_inicio IS NULL AND fecha_termino IS NULL)
                OR
                (tipo = 'arriendo' AND expira_en IS NULL
                 AND fecha_inicio IS NOT NULL AND fecha_termino IS NOT NULL
                 AND fecha_termino >= fecha_inicio)
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_operaciones_renovaciones
            CHECK (renovaciones >= 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('operaciones');
    }
};