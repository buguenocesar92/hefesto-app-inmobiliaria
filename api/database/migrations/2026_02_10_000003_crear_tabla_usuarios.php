<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usuarios: las personas de la inmobiliaria (corredores y administracion).
 *
 * El corredor es a la vez la persona que entra al sistema y la cartera que
 * lleva. No hay una tabla `corredores` separada: separarla obligaria a
 * sincronizar dos identidades para la misma persona y abriria la puerta a que
 * la cartera y el usuario dejen de coincidir.
 *
 * `correlacion_identidad` es la correlacion del equipo de Authentik. Es la
 * UNICA fuente de verdad sobre quien es la persona; el nombre y el correo son
 * copia para mostrar, y se sincronizan en cada ingreso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            // Del equipo de Authentik. Un usuario no se crea desde la
            // aplicacion: se crea al primer ingreso, y solo si su equipo ya
            // existe en la identidad.
            $tabla->string('correlacion_identidad', 160)->unique();

            $tabla->string('nombre', 160);
            $tabla->string('correo', 160);

            // `administrador` ve toda la inmobiliaria. `corredor` ve solo su
            // cartera: las propiedades y clientes que tiene asignados.
            $tabla->string('rol', 20)->default('corredor');

            // Suspender un corredor sin borrarlo: sus cambios de estado
            // historicos tienen que seguir atribuyendose a el.
            $tabla->boolean('activo')->default(true);

            $tabla->timestampTz('ultimo_ingreso')->nullable();
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'rol']);
        });

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_usuarios_rol
            CHECK (rol IN ('administrador', 'corredor'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};