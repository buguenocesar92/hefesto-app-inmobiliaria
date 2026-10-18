<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes: lo que un cliente pide.
 *
 * LA RELACION VARIOS A VARIOS, Y NO ES OPCIONAL
 * ---------------------------------------------
 * El enunciado del negocio tiene dos hechos que se contradicen si
 * se los simplifica:
 *
 *   - Un cliente tiene VARIAS solicitudes activas a la vez (por ejemplo, busca
 *     para arriendo en La Florida y para venta en Las Condes). La cartera no es
 *     una fila: es un conjunto.
 *   - Una solicitud puede requerir VARIAS propiedades (el cliente dice "cualquier
 *     cosa de 3 dormitorios en La Florida hasta 800", y el sistema le muestra
 *     cinco). El requerimiento tampoco es una fila.
 *
 * Si se metiera `cliente_id` en `propiedades`, la segunda parte seria imposible
 * de representar: una propiedad con cuatro solicitantes no pertenece a ninguno.
 * Si se metiera `propiedad_id` en `solicitudes`, la primera parte seria igual de
 * imposible. La relacion es de varios a varios y por eso tiene tabla propia.
 *
 * ESTA TABLA ES LA UNION, Y POR ESO NO ES UNA RELACION SECUNDARIA
 * ---------------------------------------------------------------
 * `solicitud_propiedad` no es un pivote anonimo: guarda cuando se asocio, quien
 * la asocio y por que se propone esa propiedad. Sin eso no se puede responder
 * "el cliente no vio el depto porque se lo filtramos", que es una pregunta que
 * aparece en cualquier reclamo.
 *
 * ESTADO DE LA SOLICITUD
 * ----------------------
 * `estado` va aqui y no en `propiedades` por la misma razon que los estados
 * comerciales: una solicitud tiene su propio ciclo de vida, independiente del
 * inmueble, y una solicitud que no prospera NO devuelve la propiedad a ningun
 * estado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            $tabla->uuid('cliente_id');
            $tabla->uuid('corredor_id')->nullable();

            $tabla->string('tipo_operacion', 10);

            // Lo que pide. Son criterios de busqueda, no filtros obligatorios:
            // el corredor puede proponer una que se salga un poco de los.
            $tabla->string('zona', 120)->nullable();
            $tabla->string('comuna', 80)->nullable();
            $tabla->bigInteger('presupuesto_min')->nullable();
            $tabla->bigInteger('presupuesto_max')->nullable();
            $tabla->integer('dormitorios_min')->nullable();
            $tabla->integer('banos_min')->nullable();
            $tabla->integer('superficie_min_m2')->nullable();

            $tabla->string('estado', 20)->default('activa');

            $tabla->text('notas')->nullable();
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'cliente_id']);
            $tabla->index(['tenant_id', 'estado']);
            $tabla->index(['tenant_id', 'corredor_id']);
        });

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_solicitudes_tipo_operacion
            CHECK (tipo_operacion IN ('venta', 'arriendo'))
            SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_solicitudes_estado
            CHECK (estado IN ('activa', 'pausada', 'atendida', 'descartada'))
            SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_solicitudes_presupuesto
            CHECK (
                (presupuesto_min IS NULL OR presupuesto_min >= 0)
                AND (presupuesto_max IS NULL OR presupuesto_max >= 0)
                AND (presupuesto_min IS NULL OR presupuesto_max IS NULL
                     OR presupuesto_min <= presupuesto_max)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes');
    }
};