<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Propiedades: el inmueble como objeto fisico, independiente de la oferta.
 *
 * POR QUE LA OPERACION NO ESTA ACA
 * ---------------------------------
 * Una misma propiedad puede estar en venta y en arriendo al mismo tiempo, y
 * puede pasar de una a otra varias veces. Si el estado viviera en esta tabla,
 * no habria forma de decir "llevo 40 dias en venta y 200 en arriendo", que es
 * justamente la pregunta que el producto responde.
 *
 * Por eso el estado NO esta en `propiedades`: vive en `ofertas`, y una
 * propiedad tiene una oferta por cada tipo de operacion que tenga abierta.
 * Aqui quedan solo las caracteristicas fisicas y los precios de referencia.
 *
 * `valor_venta` y `valor_arriendo` son PRECIOS DE LISTA. No son el valor
 * pactado: el valor pactado es el que closed la operacion y esta en
 * `operaciones.valor_pactado`. La diferencia importa porque las comisiones se
 * calculan sobre el pactado. Ver App\Services\Operaciones\CalculadoraDeComision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('propiedades', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            // El corredor responsable. Es el segundo filtro, dentro del tenant:
            // un corredor sin cartera no ve la propiedad, aunque comparta
            // inmobiliaria. Ver docs/03.
            $tabla->uuid('corredor_id')->nullable();

            // ------------------------------------------------------------------
            // Identificacion de la propiedad
            // ------------------------------------------------------------------
            $tabla->string('codigo_interno', 40)->nullable();
            $tabla->string('direccion', 255);
            $tabla->string('comuna', 80);
            $tabla->string('region', 80)->nullable();

            // ------------------------------------------------------------------
            // Caracteristicas
            // ------------------------------------------------------------------
            // `tipo_propiedad` va como texto y no como enum: el catalogo de
            // tipos (departamento, casa, oficina, terreno,/local) depende del
            // mercado y cambia sin que el motor deba enterarse.
            $tabla->string('tipo_propiedad', 40)->default('departamento');
            $tabla->integer('dormitorios')->nullable();
            $tabla->integer('banos')->nullable();
            $tabla->integer('estacionamientos')->nullable();
            $tabla->decimal('superficie_m2', 10, 2)->nullable();
            $tabla->integer('anio_construccion')->nullable();

            $tabla->string('piso')->nullable();
            $tabla->string('departamento')->nullable();
            $tabla->boolean('amoblado')->default(false);

            $tabla->text('descripcion')->nullable();

            // ------------------------------------------------------------------
            // Precios de LISTA (no pactados)
            // ------------------------------------------------------------------
            // Pesos chilenos sin decimales. En PROPERTY el precio pactado casi
            // nunca es redondo, pero agregar decimales a los millones solo
            // aporta ruido en la interfaz.
            $tabla->bigInteger('valor_venta')->nullable();
            $tabla->bigInteger('valor_arriendo')->nullable();

            // ------------------------------------------------------------------
            //dueño
            // ------------------------------------------------------------------
            // Datos minimos para saber a quien llamar cuando algo vence. Son
            // datos personales bajo la Ley 19.628: ver docs/07.
            $tabla->string('propietario_nombre', 160)->nullable();
            $tabla->string('propietario_telefono', 30)->nullable();
            $tabla->string('propietario_correo', 160)->nullable();

            $tabla->boolean('activa')->default(true);
            $tabla->timestampsTz();

            $tabla->unique(['tenant_id', 'codigo_interno'], 'uq_propiedades_tenant_codigo');

            // El indice que sostiene el segundo filtro (la cartera del
            // corredor) y el tablero de "mis propiedades".
            $tabla->index(['tenant_id', 'corredor_id']);
            $tabla->index(['tenant_id', 'comuna']);
            $tabla->index(['tenant_id', 'tipo_propiedad']);
        });

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_propiedades_valores_no_negativos
            CHECK (
                (valor_venta IS NULL OR valor_venta >= 0)
                AND (valor_arriendo IS NULL OR valor_arriendo >= 0)
            )
            SQL);

        DB::statement(<<<'SQL'
            CREATE CONSTRAINT chk_propiedades_caracteristicas
            CHECK (
                (dormitorios IS NULL OR dormitorios >= 0)
                AND (banos IS NULL OR banos >= 0)
                AND (estacionamientos IS NULL OR estacionamientos >= 0)
                AND (superficie_m2 IS NULL OR superficie_m2 > 0)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('propiedades');
    }
};