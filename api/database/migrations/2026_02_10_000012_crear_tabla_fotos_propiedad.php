<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fotos de la propiedad.
 *
 * Van en tabla aparte y no como un arreglo JSON en `propiedades` por una razon
 * concreta: las fotos se listan (la miniatura de la ficha) y se consultan por
 * propiedad (la galeria). Con una columna JSON habria que traer la fila entera
 * para leer un campo, y las fotos son el dato mas pesado de la ficha.
 *
 * `ruta` es RELATIVA al volumen, nunca absoluta. Es la unica forma de que el
 * volumen de almacenamiento se pueda montar en otro lado sin romper lo que ya
 * se guardo, y la unica forma de que la comprobacion de ruta pueda impedir que
 * un `../` salga del volumen. Ver docs/07.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fotos_propiedad', function (Blueprint $tabla): void {
            $tabla->uuid('id')->primary();
            $tabla->uuid('tenant_id');

            $tabla->uuid('propiedad_id');

            // Relativa al volumen de almacenamiento. Sin barra inicial.
            $tabla->string('ruta', 500);

            $tabla->string('descripcion', 255)->nullable();
            $tabla->integer('orden')->default(0);

            // Primera foto: la que sale en el listado y en la tarjeta.
            $tabla->boolean('es_portada')->default(false);

            $tabla->timestampsTz();

            $tabla->index(['tenant_id', 'propiedad_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fotos_propiedad');
    }
};