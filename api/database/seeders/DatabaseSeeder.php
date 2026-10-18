<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Punto de entrada de la siembra.
 *
 * Solo corre la semilla de la inmobiliaria de ejemplo. Es la unica que hace
 * falta para levantar el sistema y ver el tablero con datos.
 *
 * Todas las escrituras de negocio van dentro de `ejecutarAislado`: la RLS esta
 * forzada y una escritura sin contexto de inmobiliaria no se guarda.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(InmobiliariaDeEjemploSeeder::class);
    }
}