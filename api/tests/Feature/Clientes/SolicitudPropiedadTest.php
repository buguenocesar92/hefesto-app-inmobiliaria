<?php

declare(strict_types=1);

namespace Tests\Feature\Clientes;

use App\Enums\TipoOperacion;
use App\Models\Cliente;
use App\Models\Inmobiliaria;
use App\Models\Oferta;
use App\Models\Propiedad;
use App\Models\Solicitud;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La relacion de varios a varios entre solicitudes y propiedades.
 *
 * "Un cliente puede tener varias solicitudes activas y una solicitud puede
 * requerir varias propiedades. La relacion es de varios a varios y no es
 * opcional."
 *
 * LAS TRES FORMAS EN QUE ESO SE ROMPE
 * -----------------------------------
 *   1. Meter `solicitud_id` en `propiedades`: una propuesta con cuatro
 *      solicitantes tendria que pertenecer a uno solo. Se comprueba que una
 *      propiedad puede estar en varias solicitudes.
 *   2. Meter `propiedad_id` en `solicitudes`: cada solicitud podria requerir un
 *      solo inmueble. Se comprueba que una solicitud tiene varias propiedades.
 *   3. Tratar al cliente como una sola solicitud: se comprueba que un cliente
 *      tiene varias solicitudes activas a la vez.
 *
 * Y se comprueba que la union guarde sus propios datos, porque una propuesta no
 * es solo un par de ids: es un hecho con fecha, responsable y resultado.
 */
class SolicitudPropiedadTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_cliente_puede_tener_varias_solicitudes_activas(): void
    {
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $corredor = $this->crearUsuario($inmobiliaria, 'corredor', 'Corredor');
        $cliente = $this->crearCliente($inmobiliaria, $corredor, 'Ana');

        $this->dentroDe($inmobiliaria, function () use ($inmobiliaria, $cliente, $corredor): void {
            // Busca para arriendo en un sitio y para venta en otro, a la vez.
            Solicitud::query()->create([
                'tenant_id' => $inmobiliaria->id,
                'cliente_id' => $cliente->id,
                'corredor_id' => $corredor->id,
                'tipo_operacion' => 'arriendo',
                'comuna' => 'La Florida',
                'presupuesto_max' => 700_000,
                'dormitorios_min' => 3,
                'estado' => 'activa',
            ]);

            Solicitud::query()->create([
                'tenant_id' => $inmobiliaria->id,
                'cliente_id' => $cliente->id,
                'corredor_id' => $corredor->id,
                'tipo_operacion' => 'venta',
                'comuna' => 'Las Condes',
                'presupuesto_max' => 150_000_000,
                'estado' => 'activa',
            ]);
        });

        $activas = $this->dentroDe($inmobiliaria, fn (): int => Solicitud::query()
            ->where('cliente_id', $cliente->id)
            ->where('estado', 'activa')
            ->count());

        $this->assertSame(2, $activas, 'Un cliente debe poder tener varias solicitudes activas.');
    }

    public function test_una_solicitud_puede_tener_varias_propiedades(): void
    {
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $corredor = $this->crearUsuario($inmobiliaria, 'corredor', 'Corredor');
        $cliente = $this->crearCliente($inmobiliaria, $corredor, 'Ana');

        $solicitud = $this->dentroDe($inmobiliaria, fn (): Solicitud => Solicitud::query()->create([
            'tenant_id' => $inmobiliaria->id,
            'cliente_id' => $cliente->id,
            'corredor_id' => $corredor->id,
            'tipo_operacion' => 'arriendo',
            'comuna' => 'La Florida',
            'dormitorios_min' => 3,
            'estado' => 'activa',
        ]));

        $ofertas = [];

        foreach (['Propiedad A', 'Propiedad B', 'Propiedad C'] as $direccion) {
            $ofertas[] = $this->crearOfertaDeVenta($inmobiliaria, $corredor, ['direccion' => $direccion]);
        }

        $this->proponerTodas($inmobiliaria, $solicitud, $ofertas, $corredor);

        $propiedades = $this->dentroDe($inmobiliaria, fn (): \Illuminate\Support\Collection => $solicitud
            ->fresh()
            ->propiedades()
            ->get());

        $this->assertCount(3, $propiedades, 'Una solicitud puede requerir varias propiedades.');
    }

    public function test_una_propiedad_puede_estar_en_varias_solicitudes(): void
    {
        // Esta es la direccion en la que se rompe la solucion de "poner
        // solicitud_id en propiedades": una propiedad attractive para tres
        // clientes a la vez no pertenece a uno solo.
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $corredor = $this->crearUsuario($inmobiliaria, 'corredor', 'Corredor');

        $oferta = $this->crearOfertaDeVenta($inmobiliaria, $corredor, ['direccion' => 'Propiedad codiciada']);

        $solicitudes = [];

        foreach (['Ana', 'Beto', 'Caro'] as $nombre) {
            $cliente = $this->crearCliente($inmobiliaria, $corredor, $nombre);

            $solicitudes[] = $this->dentroDe($inmobiliaria, fn (): Solicitud => Solicitud::query()->create([
                'tenant_id' => $inmobiliaria->id,
                'cliente_id' => $cliente->id,
                'corredor_id' => $corredor->id,
                'tipo_operacion' => 'venta',
                'estado' => 'activa',
            ]));
        }

        foreach ($solicitudes as $solicitud) {
            $this->proponerTodas($inmobiliaria, $solicitud, [$oferta], $corredor);
        }

        $solicitudesDeLaPropiedad = $this->dentroDe($inmobiliaria, fn (): int => DB::table('solicitud_propiedad')
            ->where('propiedad_id', $oferta->propiedad_id)
            ->count());

        $this->assertSame(3, $solicitudesDeLaPropiedad);
    }

    public function test_la_union_guarda_sus_propios_datos(): void
    {
        // Una propuesta no es un par de ids: es un hecho con fecha, responsable
        // y como termino. Sin eso no se puede responder "el cliente no vio este
        // depto porque se lo filtramos", que aparece en cualquier reclamo.
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $corredor = $this->crearUsuario($inmobiliaria, 'corredor', 'Corredor');
        $cliente = $this->crearCliente($inmobiliaria, $corredor, 'Ana');

        $solicitud = $this->dentroDe($inmobiliaria, fn (): Solicitud => Solicitud::query()->create([
            'tenant_id' => $inmobiliaria->id,
            'cliente_id' => $cliente->id,
            'corredor_id' => $corredor->id,
            'tipo_operacion' => 'venta',
            'estado' => 'activa',
        ]));

        $oferta = $this->crearOfertaDeVenta($inmobiliaria, $corredor);

        $this->proponerTodas($inmobiliaria, $solicitud, [$oferta], $corredor);

        $fila = $this->dentroDe($inmobiliaria, fn (): object => DB::table('solicitud_propiedad')
            ->where('solicitud_id', $solicitud->id)
            ->where('propiedad_id', $oferta->propiedad_id)
            ->firstOrFail());

        $this->assertSame((string) $corredor->id, (string) $fila->usuario_id);
        $this->assertSame('pendiente', (string) $fila->estado);
        $this->assertNotNull($fila->propuesta_en);
    }

    public function test_la_misma_propiedad_no_se_propone_dos_veces_a_la_misma_solicitud(): void
    {
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $corredor = $this->crearUsuario($inmobiliaria, 'corredor', 'Corredor');
        $cliente = $this->crearCliente($inmobiliaria, $corredor, 'Ana');

        $solicitud = $this->dentroDe($inmobiliaria, fn (): Solicitud => Solicitud::query()->create([
            'tenant_id' => $inmobiliaria->id,
            'cliente_id' => $cliente->id,
            'corredor_id' => $corredor->id,
            'tipo_operacion' => 'venta',
            'estado' => 'activa',
        ]));

        $oferta = $this->crearOfertaDeVenta($inmobiliaria, $corredor);

        $this->proponerTodas($inmobiliaria, $solicitud, [$oferta], $corredor);

        // El indice unico `uq_solicitud_propiedad` lo impide en la base.
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->proponerTodas($inmobiliaria, $solicitud, [$oferta], $corredor);
    }

    public function test_no_se_propone_una_propiedad_sin_oferta_del_tipo_pedido(): void
    {
        // Proponer a una solicitud de arriendo un departamento que solo esta
        // en venta es un error de la persona que propone, no un dato valido.
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $corredor = $this->crearUsuario($inmobiliaria, 'corredor', 'Corredor');
        $cliente = $this->crearCliente($inmobiliaria, $corredor, 'Ana');

        $solicitudDeArriendo = $this->dentroDe($inmobiliaria, fn (): Solicitud => Solicitud::query()->create([
            'tenant_id' => $inmobiliaria->id,
            'cliente_id' => $cliente->id,
            'corredor_id' => $corredor->id,
            'tipo_operacion' => 'arriendo',
            'estado' => 'activa',
        ]));

        // Oferta SOLO de venta.
        $ofertaDeVenta = $this->crearOfertaDeVenta($inmobiliaria, $corredor);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->proponerTodas($inmobiliaria, $solicitudDeArriendo, [$ofertaDeVenta], $corredor);
    }

    public function test_un_cliente_solo_ve_las_solicitudes_de_su_cartera(): void
    {
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $corredorAna = $this->crearUsuario($inmobiliaria, 'corredor', 'Ana');
        $corredorBeto = $this->crearUsuario($inmobiliaria, 'corredor', 'Beto');

        $clienteDeAna = $this->crearCliente($inmobiliaria, $corredorAna, 'ClienteAna');
        $this->crearCliente($inmobiliaria, $corredorBeto, 'ClienteBeto');

        $this->dentroDe($inmobiliaria, fn () => Solicitud::query()->create([
            'tenant_id' => $inmobiliaria->id,
            'cliente_id' => $clienteDeAna->id,
            'corredor_id' => $corredorAna->id,
            'tipo_operacion' => 'venta',
            'estado' => 'activa',
        ]));

        $listado = $this->actingAs($corredorAna)
            ->getJson('/api/v1/clientes/' . $clienteDeAna->id . '/solicitudes')
            ->assertOk()
            ->json('datos');

        $this->assertCount(1, $listado);

        // Y el cliente de Beto es 404, no 403.
        $clienteDeBeto = $this->dentroDe($inmobiliaria, fn (): Cliente => Cliente::query()
            ->where('nombres', 'ClienteBeto')
            ->firstOrFail());

        $this->actingAs($corredorAna)
            ->getJson('/api/v1/clientes/' . $clienteDeBeto->id . '/solicitudes')
            ->assertNotFound();
    }

    /**
     * Escribe la union para varias ofertas.
     *
     * @param  list<Oferta>  $ofertas
     */
    private function proponerTodas(
        Inmobiliaria $inmobiliaria,
        Solicitud $solicitud,
        array $ofertas,
        \App\Models\Usuario $usuario,
    ): void {
        $this->dentroDe($inmobiliaria, function () use ($solicitud, $ofertas, $usuario): void {
            foreach ($ofertas as $oferta) {
                $tieneOfertaDelTipo = $oferta->tipo_operacion === TipoOperacion::from(
                    $solicitud->tipo_operacion->value
                );

                if (! $tieneOfertaDelTipo) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'propiedad_id' => 'Tipo de operacion distinto.',
                    ]);
                }

                DB::table('solicitud_propiedad')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'tenant_id' => $solicitud->tenant_id,
                    'solicitud_id' => $solicitud->id,
                    'propiedad_id' => $oferta->propiedad_id,
                    'usuario_id' => (string) $usuario->getKey(),
                    'propuesta_en' => now(),
                    'estado' => 'pendiente',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }
}