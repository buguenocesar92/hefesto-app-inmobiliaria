<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clientes: los solicitantes de una inmobiliaria.
 *
 * Son datos personales bajo la Ley 19.628. Ver docs/07 para el criterio de
 * tratamiento, la retencion y el consentimiento.
 *
 * `consentimiento_contacto` y `consentimiento_fecha` existen porque el motivo
 * por el que esta aplicacion guarda un telefono y un correo es la atencion de
 * una solicitud. Sin esa base, es un dato guardado sin finalidad declarada, que
 * es justo lo que la Ley 19.628 prohibe en su tratamiento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            // Cartera. Un corredor sin cartera no ve al cliente, aunque sea de
            // la misma inmobiliaria. Es el segundo filtro, dentro del tenant.
            $tabla->uuid('corredor_id')->nullable();

            $tabla->string('nombres', 80);
            $tabla->string('apellidos', 80);
            $tabla->string('telefono', 30)->nullable();
            $tabla->string('correo', 160)->nullable();

            $tabla->text('notas')->nullable();

            // Consentimiento para guardar y usar los datos de contacto.
            $tabla->boolean('consentimiento_contacto')->default(false);
            $tabla->timestampTz('consentimiento_fecha')->nullable();

            $tabla->boolean('activo')->default(true);
            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'corredor_id']);
            $tabla->index(['tenant_id', 'activo']);

            // Un cliente no se archiva: se marca inactivo. Sus visitas y sus
            // operaciones quedan y hay que poder leerlas para un reclamo.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes');
    }
};