<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row Level Security: el aislamiento entre inmobiliarias lo impone PostgreSQL.
 *
 * MISMO PATRON DEL PORTAL DE AUTOSERVICIO, MISMA JUSTIFICACION
 * ------------------------------------------------------------
 * La justificacion completa esta en
 * apps/portal-autoservicio/docs/03-MULTITENANCY.md y se repite, adaptada a
 * este dominio, en docs/03-MULTITENANCY-Y-ROLES.md. La decision fue, y aqui se
 * repite tal cual: columna `tenant_id` mas politicas de RLS, NO un esquema de
 * base de datos por inmobiliaria.
 *
 * TRES COSAS QUE ESTA MIGRACION HACE Y QUE HAY QUE ENTENDER
 *
 * 1. `public.app_tenant_id()` LANZA EXCEPCION si no hay contexto.
 *    Si devolviera NULL, una consulta sin `tenant_id` daria "cero filas" en vez
 *    de fallar. Falla cerrado: la ausencia de contexto es un error, no una
 *    condicion legitima.
 *
 * 2. `FORCE ROW LEVEL SECURITY` en cada tabla. Sin esto las politicas NO le
 *    aplican al dueno de la tabla, y Laravel se conecta como el dueno (el
 *    mismo usuario que migra). Un RLS de adorno no protege nada.
 *
 * 3. La politica se aplica por `tenant_id`, que todas las tablas de negocio
 *    tienen. Sin funcion `SECURITY DEFINER` a proposito: una funcion definida
 *    por el dueno saltaria sus propias politicas.
 *
 * POR QUE `cambios_estado` ESTA DENTRO Y ES ESPECIALMENTE IMPORTANTE
 * -----------------------------------------------------------------
 * `cambios_estado` es el historial. Si un corredor de la inmobiliaria A pudiera
 * leer los cambios de estado de la B, sabria el precio pactado de una operacion
 * ajena a traves del motivo, y sobre todo sabria COMO TRABAJA el otro: cuantos
 * dias demora en reservar, cuantas reservas se le caen. Esa es informacion
 * comercial de la competencia. Va con RLS como cualquier otra.
 *
 * EL PUNTO CIEGO: `inmobiliarias`
 * -------------------------------
 * No lleva RLS a proposito: se consulta en el login, cuando todavia no hay
 * sesion y por lo tanto no hay `app.tenant_id`. Compensaciones: la vista
 * `vw_inmobiliarias_publicas` de mas abajo, y un rol de base que no sea
 * superusuario ni tenga BYPASSRLS (se comprueba con
 * `inmobiliaria:verificar-aislamiento`). Mismo riesgo residual que en el portal:
 * un `slug` se puede enumerar antes de autenticarse. Ver docs/03.
 */
return new class extends Migration
{
    /**
     * Tablas de negocio, todas con `tenant_id`.
     *
     * @var list<string>
     */
    private const TABLAS = [
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

    public function up(): void
    {
        $this->crearFuncionDeContexto();
        $this->crearVistaPublica();

        foreach (self::TABLAS as $tabla) {
            $this->proteger($tabla);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLAS) as $tabla) {
            DB::statement('ALTER TABLE ' . $tabla . ' NO FORCE ROW LEVEL SECURITY');
            DB::statement('DROP POLICY IF EXISTS aislamiento_tenant ON ' . $tabla);
        }

        DB::statement('DROP VIEW IF EXISTS public.vw_inmobiliarias_publicas');
        DB::statement('DROP FUNCTION IF EXISTS public.app_tenant_id()');
    }

    /**
     * Funcion que lee la inmobiliaria de la peticion.
     *
     * `current_setting(..., true)` devuelve NULL si la variable no existe, en
     * vez de fallar, que es justamente el caso que hay que detectar.
     */
    private function crearFuncionDeContexto(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.app_tenant_id() RETURNS uuid
            LANGUAGE plpgsql
            STABLE
            AS $$
            DECLARE
                valor text;
            BEGIN
                valor := current_setting('app.tenant_id', true);

                IF valor IS NULL OR valor = '' THEN
                    RAISE EXCEPTION
                        'Falta el contexto de inmobiliaria (app.tenant_id). '
                        'Usa App\Services\Tenancy\ContextoTenant::ejecutarAislado().'
                        USING ERRCODE = 'insufficient_privilege';
                END IF;

                RETURN valor::uuid;
            END;
            $$
            SQL);
    }

    /**
     * Compensacion 1 del punto ciego.
     *
     * Antes del login la aplicacion no lee `inmobiliarias` crudo: lee esta
     * vista, que expone SOLO tres columnas y solo de las activas. No expone
     * razon social, RUT, direccion, telefono ni correo.
     */
    private function crearVistaPublica(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIEW public.vw_inmobiliarias_publicas AS
            SELECT slug, nombre, activo
            FROM public.inmobiliarias
            WHERE activo
            SQL);
    }

    private function proteger(string $tabla): void
    {
        DB::statement('ALTER TABLE ' . $tabla . ' ENABLE ROW LEVEL SECURITY');

        // Sin esto, las politicas no aplican al dueno de la tabla.
        DB::statement('ALTER TABLE ' . $tabla . ' FORCE ROW LEVEL SECURITY');

        DB::statement(<<<SQL
            CREATE POLICY aislamiento_tenant ON {$tabla}
                USING (tenant_id = public.app_tenant_id())
                WITH CHECK (tenant_id = public.app_tenant_id())
            SQL);
    }
};