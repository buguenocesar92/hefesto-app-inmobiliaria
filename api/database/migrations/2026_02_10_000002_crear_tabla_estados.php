<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Estados comercializables: el catalogo que hace medible el negocio.
 *
 * POR QUE SON DATOS Y NO UN ENUM DE PHP
 * -------------------------------------
 * El producto no es "la propiedad esta vendida": es "esta propiedad lleva 214
 * dias en RESERVADA". Para poder medir eso, los estados tienen que ser filas que
 * cada inmobiliaria configura, no valores de un enum cerrado en el codigo. Una
 * inmobiliaria que llama "en oferta" a lo que otra llama "disponible" tiene que
 * poder decirlo, y tiene que poder agregar un estado propio ("en evaluacion
 * del propietario") sin que Hefesto tenga que liberar una version.
 *
 * El enum de PHP queda como catalogo de los estados MINIMOS que el motor
 * necesita para funcionar (ver App\Enums\EstadoComercial): la reserva se
 * vence, el arriendo termina. El resto es configuracion del tenant.
 *
 * `tipo_operacion` en ('ambas', 'venta', 'arriendo') decide si un estado
 * aplica a las ofertas de venta, a las de arriendo o a las dos. Es lo que
 * permite tener "escriturado" sin que aparezca en el flujo de arriendo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estados', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            $tabla->string('nombre', 80);
            $tabla->text('descripcion')->nullable();

            // Correspondencia con los estados que el MOTOR necesita reconocer,
            // por `clave_sistema` y no por nombre. Ver App\Enums\EstadoComercial.
            // NULL para los estados que son solo configuracion de la
            // inmobiliaria: el motor no tiene ninguna regla que dependa de ellos.
            $tabla->string('clave_sistema', 30)->nullable();

            $tabla->string('tipo_operacion', 10)->default('ambas');

            // Un solo estado terminal puede marcar el fin de cada flujo. La
            // reserva vencida NO es un estado: es el retorno automatico al
            // estado disponible, que se registra en `cambios_estado`.
            $tabla->boolean('es_inicial')->default(false);
            $tabla->boolean('es_final')->default(false);

            // Orden en la linea de tiempo de la interfaz.
            $tabla->integer('posicion')->default(0);

            // Para el tablero: un color por estado y asi el grafico se lee sin
            // tener que distinguir tonos parecidos.
            $tabla->string('color', 9)->default('#4a6fa5');

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            // Una inmobiliaria no puede tener dos estados que se llamen igual en
            // el mismo tipo de operacion: "disponible" y "disponible" harian
            // ambigua la lectura de la linea de tiempo.
            $tabla->unique(
                ['tenant_id', 'nombre', 'tipo_operacion'],
                'uq_estados_tenant_nombre_tipo'
            );

            $tabla->index(['tenant_id', 'activo']);

            // La clave de sistema identifica UN estado por tipo de operacion
            // dentro de la inmobiliaria. Sin este indice unico, dos estados
            // marcados como "disponible" harian que la liberacion automatica
            // elegira uno al azar.
            $tabla->unique(
                ['tenant_id', 'clave_sistema', 'tipo_operacion'],
                'uq_estados_tenant_clave_tipo'
            );
        });

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_estados_tipo_operacion
            CHECK (tipo_operacion IN ('ambas', 'venta', 'arriendo'))
            SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_estados_color
            CHECK (color ~ '^#[0-9A-Fa-f]{6}$')
            SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_estados_posicion
            CHECK (posicion >= 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('estados');
    }
};