<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inmobiliarias: los tenants de esta aplicacion.
 *
 * Esta tabla NO lleva Row Level Security, a proposito. Se consulta durante el
 * login, cuando todavia no hay sesion y por lo tanto no hay `app.tenant_id` que
 * poner. Es la misma excepcion consciente que toma
 * apps/portal-autoservicio/docs/03-MULTITENANCY.md para su tabla `tenants`, con
 * las mismas dos compensaciones:
 *
 *   1. La vista `vw_inmobiliarias_publicas` (creada en la migracion de RLS),
 *      que expone solo `nombre`, `slug` y `activo` de las inmobiliarias activas.
 *   2. El rol de la aplicacion no es superusuario ni tiene BYPASSRLS; se
 *      comprueba con `inmobiliaria:verificar-aislamiento`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inmobiliarias', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();

            // Correlacion con la identidad: el equipo de Authentik se llama
            // `<prefijo>:<correlacion>`, por ejemplo `HefestoInmobiliaria:sur`.
            $tabla->string('correlacion_identidad', 100)->unique();

            $tabla->string('slug', 100)->unique();
            $tabla->string('nombre', 160);
            $tabla->string('razon_social', 200)->nullable();
            $tabla->string('rut', 20)->nullable();

            $tabla->string('direccion', 255)->nullable();
            $tabla->string('telefono', 30)->nullable();
            $tabla->string('correo', 160)->nullable();

            // En Santiago el invierno es las 23:00; el horario de atencion del
            // corredor se muestra siempre en hora local.
            $tabla->string('zona_horaria', 64)->default('America/Santiago');

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->index(['activo']);
        });

        // Restriccion de dominio, no de integridad: el RUT chileno tiene dos
        // tipos de digito verificador. Se valida el formato, no el digito.
        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_inmobiliarias_rut
            CHECK (rut IS NULL OR rut ~ '^[0-9]{7,8}-[0-9Kk]$')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inmobiliarias');
    }
};