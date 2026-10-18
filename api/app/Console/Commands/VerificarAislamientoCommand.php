<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comprueba que el aislamiento entre inmobiliarias este realmente puesto.
 *
 * QUE COMPRUEBA
 * -------------
 *  1. Que el rol de la base NO sea superusuario.
 *  2. Que el rol NO tenga `BYPASSRLS` (que saltaria todas las politicas).
 *  3. Que TODAS las tablas de negocio tengan la politica `aislamiento_tenant`.
 *  4. Que todas tengan `FORCE ROW LEVEL SECURITY` ACTIVADO.
 *  5. Que la funcion de contexto FALLE CERRADO: sin `app.tenant_id`, una
 *     consulta a una tabla protegida tiene que lanzar excepcion, no devolver
 *     cero filas.
 *
 * POR QUE EL PUNTO 5 SE COMPRUEBA Y NO SE DA POR HECHO
 * ---------------------------------------------------
 * Es el punto donde un cambio de alguien puede romper el aislamiento sin romper
 * nada visible. Si la funcion pasara a devolver NULL en vez de lanzar, todas las
 * consultas seguirian "funcionando" y devolverian cero filas, y nadie se enteraria
 * hasta que un reporte saliera vacio sin explicacion.
 *
 * CUANDO CORRE
 * ------------
 * En el despliegue, antes de dar por buena la instalacion. Sale distinto de cero
 * si algo falla, para que el despliegue se detenga.
 */
class VerificarAislamientoCommand extends Command
{
    protected $signature = 'inmobiliaria:verificar-aislamiento';

    protected $description = 'Verifica que el aislamiento entre inmobiliarias este puesto';

    /**
     * Tablas que tienen que estar protegidas.
     *
     * @var list<string>
     */
    private const TABLAS_PROTEGIDAS = [
        'usuarios',
        'estados',
        'propiedades',
        'fotos_propiedad',
        'ofertas',
        'cambios_estado',
        'clientes',
        'solicitudes',
        'solicitud_propiedad',
        'visitas',
        'operaciones',
    ];

    public function handle(): int
    {
        $problemas = 0;

        $problemas += $this->revisarRol();
        $problemas += $this->revisarPoliticas();
        $problemas += $this->revisarQueFallaCerrado();

        if ($problemas > 0) {
            $this->error('Se encontraron ' . $problemas . ' problema(s) de aislamiento.');

            return self::FAILURE;
        }

        $this->info('Aislamiento correcto: ' . count(self::TABLAS_PROTEGIDAS) . ' tablas protegidas.');

        return self::SUCCESS;
    }

    /**
     * El rol de la aplicacion no puede saltarse la RLS.
     */
    private function revisarRol(): int
    {
        $problemas = 0;
        $rol = (string) config('database.connections.pgsql.username');

        $fila = DB::selectOne(
            'SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = ?',
            [$rol]
        );

        if ($fila === null) {
            $this->error('El rol ' . $rol . ' no existe en la base.');

            return 1;
        }

        if ((bool) $fila->rolsuper) {
            $this->error('El rol ' . $rol . ' es SUPERUSUARIO: saltaria todas las politicas.');

            $problemas++;
        }

        if ((bool) $fila->rolbypassrls) {
            $this->error('El rol ' . $rol . ' tiene BYPASSRLS: saltaria todas las politicas.');

            $problemas++;
        }

        if ($problemas === 0) {
            $this->line('  rol ' . $rol . ': sin privilegios que salten la RLS.');
        }

        return $problemas;
    }

    /**
     * Cada tabla protegida tiene su politica y su FORCE.
     */
    private function revisarPoliticas(): int
    {
        $problemas = 0;

        foreach (self::TABLAS_PROTEGIDAS as $tabla) {
            $estado = DB::selectOne(
                'SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?',
                [$tabla]
            );

            if ($estado === null) {
                $this->error('La tabla ' . $tabla . ' no existe.');

                $problemas++;

                continue;
            }

            if (! (bool) $estado->relrowsecurity) {
                $this->error('La tabla ' . $tabla . ' NO tiene Row Level Security activado.');

                $problemas++;
            }

            if (! (bool) $estado->relforcerowsecurity) {
                // Sin FORCE, las politicas no le aplican al dueno de la tabla, y
                // Laravel se conecta como el dueno.
                $this->error('La tabla ' . $tabla . ' NO tiene FORCE ROW LEVEL SECURITY.');

                $problemas++;
            }

            $politicas = DB::select(
                'SELECT policyname FROM pg_policies WHERE tablename = ?',
                [$tabla]
            );

            $nombres = array_map(fn ($p): string => (string) $p->policyname, $politicas);

            if (! in_array('aislamiento_tenant', $nombres, true)) {
                $this->error('La tabla ' . $tabla . ' no tiene la politica aislamiento_tenant.');

                $problemas++;
            }
        }

        return $problemas;
    }

    /**
     * La funcion de contexto tiene que FALLAR, no devolver nada.
     */
    private function revisarQueFallaCerrado(): int
    {
        $problemas = 0;

        try {
            // Sin contexto. La funcion deberia lanzar excepcion.
            DB::select('SELECT public.app_tenant_id()');

            $this->error(
                'public.app_tenant_id() NO lanzo excepcion sin contexto. '
                . 'El aislamiento dejaria de fallar cerrado.'
            );

            $problemas++;
        } catch (\Illuminate\Database\QueryException $e) {
            $this->line('  public.app_tenant_id() falla cerrado sin contexto, como debe.');
        }

        return $problemas;
    }
}