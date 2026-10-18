<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Estado;
use App\Models\Inmobiliaria;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Base de las pruebas que necesitan una base VACIA.
 *
 * ESTA CLASE SE USA SOLO POR RefreshDatabase
 * -----------------------------------------
 * La diferencia con Tests\TestCase es una sola, y es importante: aqui se
 * BORRA y se vuelve a crear el esquema con las migraciones, porque las pruebas
 * de este grupo escriben filas que tienen que respetar la RLS desde el primer
 * momento.
 *
 * La RLS se activa al final de la ultima migracion. Con `RefreshDatabase` se
 * corre el esquema completo en cada prueba, asi que las tablas empiezan ya
 * protegidas. Sin ese refresco, una prueba que escribiera datos antes de que
 * existiera la politica pasaria sin comprobar nada.
 */
abstract class TestCaseConBaseLimpia extends TestCase
{
    use RefreshDatabase;

    /**
     * Una inmobiliaria con sus estados, sin tocar nada mas.
     *
     * @return array{inmobiliaria: Inmobiliaria, estados: array<string, Estado>}
     */
    protected function inmobiliariaConEstados(string $nombre = 'Inmobiliaria de prueba'): array
    {
        $inmobiliaria = $this->crearInmobiliaria($nombre);

        return [
            'inmobiliaria' => $inmobiliaria,
            'estados' => $this->dentroDe($inmobiliaria, fn (): array => $this->leerEstados($inmobiliaria)),
        ];
    }

    /**
     * @return array<string, Estado>
     */
    private function leerEstados(Inmobiliaria $inmobiliaria): array
    {
        $estados = [];

        foreach (Estado::query()->where('tenant_id', $inmobiliaria->id)->get() as $estado) {
            if ($estado->clave_sistema !== null) {
                $estados[$estado->clave_sistema] = $estado;
            }
        }

        return $estados;
    }
}