<?php

declare(strict_types=1);

namespace Tests\Feature\Aislamiento;

use App\Models\CambioEstado;
use App\Models\Cliente;
use App\Models\Inmobiliaria;
use App\Models\Oferta;
use App\Models\Propiedad;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dos inmobiliarias en la MISMA instalacion no pueden verse.
 *
 * Esta es la suite que mas importa del proyecto, y la que mas conviene correr
 * primero. No prueba la aplicacion: prueba que la base de datos se niegue.
 *
 * CUBRE
 *   1. Una consulta con el contexto de la inmobiliaria A no devuelve filas de la B.
 *   2. Sin contexto, cualquier consulta a una tabla protegida falla.
 *   3. No se puede escribir una fila declarando una inmobiliaria que no es la del
 *      contexto (`WITH CHECK`).
 *   4. Por la API, una peticion de la A recibe 404 al pedir un recurso de la B.
 *   5. `cambios_estado` tambien esta aislada, y por una razon concreta: el
 *      historial de la competencia dice cuanto tarda en vender y cuantas
 *      reservas se le caen.
 *   6. El contexto NO sobrevive entre iteraciones, que es lo que evita que un
 *      worker de colas arrastre el tenant de otra inmobiliaria.
 */
class AislamientoEntreInmobiliariasTest extends TestCase
{
    use RefreshDatabase;

    public function test_una_inmobiliaria_no_ve_propiedades_de_otra(): void
    {
        $a = $this->crearInmobiliaria('Inmobiliaria A');
        $b = $this->crearInmobiliaria('Inmobiliaria B');

        $ofertaB = $this->crearOfertaDeVenta($b, null, [
            'direccion' => 'Propiedad exclusiva de B',
        ]);

        $vistasPorA = $this->dentroDe($a, fn (): array => Propiedad::query()->pluck('id')->all());

        $this->assertNotContains($ofertaB->propiedad_id, $vistasPorA);

        $encontrada = $this->dentroDe($a, fn (): ?Propiedad => Propiedad::query()->find($ofertaB->propiedad_id));

        $this->assertNull($encontrada, 'La RLS deberia ocultar la propiedad de la otra inmobiliaria.');
    }

    public function test_una_inmobiliaria_no_ve_clientes_de_otra(): void
    {
        $a = $this->crearInmobiliaria('Inmobiliaria A');
        $b = $this->crearInmobiliaria('Inmobiliaria B');

        $clienteB = $this->crearCliente($b, null, 'ClienteConfidencialB');

        $vistosPorA = $this->dentroDe($a, fn (): array => Cliente::query()->pluck('id')->all());

        $this->assertSame([], $vistosPorA, 'A no deberia ver ningun cliente ajeno.');
        $this->assertNotContains($clienteB->id, $vistosPorA);

        // Y al reves: B si ve su propio cliente. El filtro no es "nadie ve
        // nada", es "cada uno ve lo suyo".
        $vistosPorB = $this->dentroDe($b, fn (): int => Cliente::query()->count());

        $this->assertSame(1, $vistosPorB);
    }

    public function test_el_historial_de_estados_tambien_esta_aislado(): void
    {
        // Esta prueba es la mas especifica de este archivo. `cambios_estado` es
        // la tabla mas sensible del producto: el motivo de un cambio y su
        // duracion dicen como trabaja el otro.
        $a = $this->crearInmobiliaria('Inmobiliaria A');
        $b = $this->crearInmobiliaria('Inmobiliaria B');

        $ofertaB = $this->crearOfertaDeVenta($b);

        // Con el contexto de A, el historial de la oferta de B no existe.
        $tramosVistosPorA = $this->dentroDe($a, fn (): array => CambioEstado::query()->pluck('id')->all());

        $this->assertSame(
            [],
            $tramosVistosPorA,
            'A no deberia ver ni un tramo del historial de B.'
        );

        // Y el cambio de estado de una oferta ajena tampoco se puede escribir.
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->dentroDe($a, function () use ($ofertaB, $b): void {
            CambioEstado::query()->create([
                'tenant_id' => $ofertaB->tenant_id,
                'oferta_id' => $ofertaB->id,
                'estado_anterior_id' => null,
                'estado_nuevo_id' => $ofertaB->estado_id,
                'desde' => now(),
                'hasta' => null,
                'duracion_dias' => null,
                'usuario_id' => null,
                'motivo' => 'Intruso',
            ]);
        });
    }

    public function test_sin_contexto_la_consulta_falla_y_no_devuelve_filas(): void
    {
        $a = $this->crearInmobiliaria('Inmobiliaria A');

        $this->crearCliente($a);

        // Sin `app.tenant_id`, `public.app_tenant_id()` lanza excepcion. El
        // aislamiento falla cerrado: una consulta sin contexto no devuelve
        // "cero filas y ya", falla.
        $this->expectException(\Illuminate\Database\QueryException::class);

        Cliente::query()->count();
    }

    public function test_no_se_puede_escribir_una_fila_de_otra_inmobiliaria(): void
    {
        $a = $this->crearInmobiliaria('Inmobiliaria A');
        $b = $this->crearInmobiliaria('Inmobiliaria B');

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Se escribe desde el contexto de A, declarando que la fila es de B.
        // La politica `WITH CHECK` lo prohibe.
        $this->dentroDe($a, fn (): Propiedad => Propiedad::query()->create([
            'tenant_id' => $b->id,
            'direccion' => 'Propiedad intrusa',
            'comuna' => 'Las Condes',
            'valor_venta' => 1000,
            'activa' => true,
        ]));
    }

    public function test_por_la_api_una_propiedad_de_otra_inmobiliaria_responde_404(): void
    {
        $a = $this->crearInmobiliaria('Inmobiliaria A');
        $b = $this->crearInmobiliaria('Inmobiliaria B');

        $usuarioA = $this->crearUsuario($a, 'administrador', 'Admin A');

        $ofertaB = $this->crearOfertaDeVenta($b);

        $respuesta = $this->actingAs($usuarioA)
            ->getJson('/api/v1/propiedades/' . $ofertaB->propiedad_id);

        $respuesta->assertNotFound();
    }

    public function test_el_contexto_no_sobrevive_entre_iteraciones(): void
    {
        // ESTA ES LA PRUEBA DEL WORKER DE COLAS.
        //
        // Un trabajo recorre todas las inmobiliarias en la misma conexion. Si
        // el contexto se fijara con alcance de sesion, el valor de la primera
        // inmobiliaria sobreviviria y la segunda leeria con el contexto
        // equivocado.
        //
        // Se comprueba sin necesidad de un worker: dos `ejecutarAislado`
        // seguidos en la misma conexion, que es exactamente lo que hace el
        // comando de liberacion de reservas.
        $a = $this->crearInmobiliaria('Inmobiliaria A');
        $b = $this->crearInmobiliaria('Inmobiliaria B');

        $this->crearCliente($a, null, 'ClienteDeA');
        $this->crearCliente($b, null, 'ClienteDeB');

        $contexto = app(\App\Services\Tenancy\ContextoTenant::class);

        $vistosEnA = $contexto->ejecutarAislado((string) $a->id, fn (): array => Cliente::query()->pluck('id')->all());

        // La segunda iteracion, en la misma conexion.
        $vistosEnB = $contexto->ejecutarAislado((string) $b->id, fn (): array => Cliente::query()->pluck('id')->all());

        $this->assertCount(1, $vistosEnA);
        $this->assertCount(1, $vistosEnB);
        $this->assertNotEquals($vistosEnA, $vistosEnB, 'Las dos iteraciones vieron el mismo cliente.');

        // Y al terminar la transaccion, no queda contexto. Esto es lo que hace
        // que el worker de la iteracion SIGUIENTE empiece limpio.
        $this->assertNull(
            $contexto->inmobiliariaIdEnConexion(),
            'El contexto deberia desaparecer al cerrar la transaccion.'
        );
    }

    public function test_las_inmobiliarias_no_cuentan_como_negocio_protegido(): void
    {
        // `inmobiliarias` es la excepcion consciente: se lee en el login, sin
        // sesion. Esta prueba deja constancia de que la excepcion es solo esa
        // tabla y que su vista publica no filtra datos de contacto.
        $this->crearInmobiliaria('Inmobiliaria Visible');

        $columnas = array_map(
            fn ($fila): string => (string) $fila->column_name,
            DB::select(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = 'vw_inmobiliarias_publicas'"
            )
        );

        $this->assertEqualsCanonicalizing(
            ['slug', 'nombre', 'activo'],
            $columnas,
            'La vista publica deberia exponer exactamente tres columnas.'
        );
    }
}