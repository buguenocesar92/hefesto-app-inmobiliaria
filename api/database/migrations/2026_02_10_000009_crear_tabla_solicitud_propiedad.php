<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes por propiedad: la union de la relacion de varios a varios.
 *
 * Una solicitud tiene varias propiedades y una propiedad puede estar en varias
 * solicitudes. La tabla lleva `tenant_id` como todas las de negocio, porque la
 * RLS tiene que proteger tambien las filas de union: si esta tabla se quedara
 * fuera, bastaria con incluir un `solicitud_id` ajeno para enterarse de que
 * existe, que es filtracion aunque no se vea ningun dato del cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitud_propiedad', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            $tabla->uuid('solicitud_id');
            $tabla->uuid('propiedad_id');

            // Quien propuso esa propiedad para esa solicitud.
            $tabla->uuid('usuario_id')->nullable();

            $tabla->timestampTz('propuesta_en');

            // Como le fue al cliente con esa: 'pendiente', 'visitada',
            // 'descartada', 'elegida'. Sirve para saber que propiedades se
            // visitaron de verdad, que es el numero que explica las ventas.
            $tabla->string('estado', 20)->default('pendiente');

            $tabla->string('motivo_descarte', 255)->nullable();

            $tabla->timestampsTz();

            $tabla->unique(['solicitud_id', 'propiedad_id'], 'uq_solicitud_propiedad');

            $tabla->index(['tenant_id', 'propiedad_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitud_propiedad');
    }
};