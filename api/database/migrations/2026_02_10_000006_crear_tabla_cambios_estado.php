<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cambios de estado: el historial que hace medible el negocio.
 *
 * ESTA TABLA ES EL PRODUCTO
 * --------------------------
 * Todo lo demas de esta aplicacion (fichas, clientes, visitas) es CRUD. Esto no.
 * Esta tabla responde la unica pregunta que el dueno de una inmobiliaria hoy no
 * puede responder: en cuanto dias, en promedio, se vende una propiedad reservada
 * en mi zona, y cuales de mis reservas llevan mas tiempo colgadas.
 *
 * COMO SE ESCRIBE (solo por el RegistradorDeCambioEstado)
 * -------------------------------------------------------
 * Esta tabla es SOLO AGREGAR. Nunca se actualiza una fila para "corregirla", y
 * el servicio que escribe garantiza dos cosas dentro de la misma transaccion
 * que cambia el estado de la oferta:
 *
 *   1. Cierra el tramo abierto: pone `hasta` y `duracion_minutos`.
 *   2. Abre el tramo nuevo, con `hasta` en NULL.
 *
 * Que `hasta IS NULL` signifique exactamente "el estado actual" es lo que hace
 * que la linea de tiempo se pueda leer sin joins ni logica: un
 * `WHERE hasta IS NULL` es el estado vigente, y la resta de las filas ya estan
 * medidas.
 *
 * POR QUE LA DURACION SE GUARDA Y NO SE CALCULA
 * ---------------------------------------------
 * `duracion_minutos` es derivable (`hasta - desde`), y podria haber quedado
 * fuera. Se guarda igual, por dos motivos:
 *
 *   - El calculo se hace UNA vez, en el momento en que el tramo se cierra, con
 *     la zona horaria y el reloj del servidor ya resueltos. Consultarlo de nuevo
 *     cada vez obliga a repetir esa operacion para cada fila del informe.
 *   - Un informe de permanencia que cambia de resultado porque el reloj del
 *     servidor se movio no sirve para nada. La duracion medida queda fija.
 *
 * Y para que no se pueda alguien "arreglar" la historia en silencio, un disparador
 * de PostgreSQL impide modificar `desde` una vez cerrada. Ver triggers().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cambios_estado', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            $tabla->uuid('oferta_id');

            // Estado DE DONDE se sale. NULL solo en el primer cambio de la
            // oferta, cuando no habia estado anterior.
            $tabla->uuid('estado_anterior_id')->nullable();

            $tabla->uuid('estado_nuevo_id');

            // Momento exacto en que la oferta ENTRO al estado nuevo.
            $tabla->timestampTz('desde');

            // Momento en que SALIO. NULL significa que sigue ahi: es el tramo
            // abierto, y por lo tanto el estado actual.
            $tabla->timestampTz('hasta')->nullable();

            // Dias que duro el tramo anterior, ya cerrado. NULL mientras el
            // tramo sigue abierto.
            $tabla->integer('duracion_dias')->nullable();

            // Quien lo hizo. `usuario_id` es NULL solo para los cambios que
            // hizo el sistema sin persona: la liberacion de una reserva
            // vencida. Distinguir eso de "lo hizo un corredor" importa para
            // leer la historia sin inventarse responsables.
            $tabla->uuid('usuario_id')->nullable();

            // Por que se cambio. Para los automaticos, el motivo dice quien lo
            // provoco: 'Liberacion automatica de reserva vencida'.
            $tabla->string('motivo', 255)->nullable();

            $tabla->timestampsTz();

            $tabla->index(['oferta_id', 'desde'], 'idx_cambios_oferta_desde');
            $tabla->index(['tenant_id', 'estado_nuevo_id', 'hasta'], 'idx_cambios_tenant_estado_ventana');
            $tabla->index(['tenant_id', 'usuario_id']);
        });

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_cambios_duracion_no_negativa
            CHECK (duracion_dias IS NULL OR duracion_dias >= 0)
            SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_cambios_ventana_coherente
            CHECK (hasta IS NULL OR hasta >= desde)
            SQL);

        // Un tramo cerrado tiene que traer su duracion, y uno abierto no. Sin
        // esto, un tramo podria quedar cerrado sin medir, que es exactamente
        // el dato que el producto necesita.
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_cambios_duracion_coherente
            CHECK (
                (hasta IS NULL AND duracion_dias IS NULL)
                OR (hasta IS NOT NULL AND duracion_dias IS NOT NULL)
            )
            SQL);

        // Solo un tramo abierto por oferta. Si hubiera dos, "el estado actual"
        // seria ambiguo y el tablero contaria la propiedad dos veces.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX uq_cambios_oferta_tramo_abierto
            ON public.cambios_estado (oferta_id)
            WHERE hasta IS NULL
            SQL);

        $this->triggers();
    }

    /**
     * La historia no se reescribe.
     *
     * Estas funciones bloquean dos cosas que PostgreSQL permitia y este producto
     * no: cambiar el `desde` de un tramo ya cerrado, y cerrar un tramo sin medirlo.
     */
    private function triggers(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.cambios_estado_no_reescribir() RETURNS void
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF OLD.hasta IS NOT NULL AND (NEW.desde IS DISTINCT FROM OLD.desde) THEN
                    RAISE EXCEPTION
                        'No se puede modificar el inicio de un tramo ya cerrado (cambio_estado %)'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                IF OLD.hasta IS NULL AND NEW.hasta IS NOT NULL AND NEW.duracion_dias IS NULL THEN
                    RAISE EXCEPTION
                        'Un tramo cerrado tiene que traer su duracion (cambio_estado %)'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;
            END;
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_cambios_estado_no_reescribir
            BEFORE UPDATE ON public.cambios_estado
            FOR EACH ROW
            EXECUTE FUNCTION public.cambios_estado_no_reescribir()
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS trg_cambios_estado_no_reescribir ON public.cambios_estado');
        DB::statement('DROP FUNCTION IF EXISTS public.cambios_estado_no_reescribir()');

        Schema::dropIfExists('cambios_estado');
    }
};