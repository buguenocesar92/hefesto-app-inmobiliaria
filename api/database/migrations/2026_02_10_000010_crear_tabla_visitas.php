<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Visitas: el seguimiento de a quien se le mostro la propiedad, y que paso.
 *
 * "El seguimiento de un cliente y sus visitas es lo que evita perder la venta
 * por olvido." Por eso la visita guarda el resultado, no solo la hora: una
 * visita agendada que nadie realizo es informacion distinta de una visita
 * realizada con el cliente que no quiso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitas', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            $tabla->uuid('propiedad_id');
            $tabla->uuid('cliente_id');
            $tabla->uuid('corredor_id')->nullable();

            $tabla->timestampTz('inicio');
            $tabla->integer('duracion_minutos')->default(30);

            $tabla->string('estado', 20)->default('agendada');

            // Que dijo el cliente. Es el dato que hace util la visita: sin
            // resultado, el registro no sirve para decidir nada.
            $tabla->string('resultado', 40)->nullable();
            $tabla->text('notas')->nullable();

            // Si se ofrecio algo para bajar el precio. Con frecuencia la razon
            // de que una reserva se caiga es que nadie ofrecio nada.
            $tabla->boolean('ofrecio_ajuste')->default(false);

            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'inicio']);
            $tabla->index(['tenant_id', 'cliente_id']);
            $tabla->index(['tenant_id', 'propiedad_id']);
            $tabla->index(['tenant_id', 'corredor_id']);
        });

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_visitas_estado
            CHECK (estado IN ('agendada', 'realizada', 'no_se_presento', 'cancelada', 'reprogramada'))
            SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_visitas_resultado
            CHECK (resultado IS NULL OR resultado IN (
                'interesado', 'muy_interesado', 'indeciso', 'descartado', 'otra_parte'
            ))
            SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_visitas_duracion
            CHECK (duracion_minutos > 0)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('visitas');
    }
};