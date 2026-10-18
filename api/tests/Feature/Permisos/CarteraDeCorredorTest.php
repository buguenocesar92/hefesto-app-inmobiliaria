<?php

declare(strict_types=1);

namespace Tests\Feature\Permisos;

use App\Models\Cliente;
use App\Models\Inmobiliaria;
use App\Models\Oferta;
use App\Models\Propiedad;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un corredor no ve la cartera de otro corredor DE LA MISMA inmobiliaria.
 *
 * POR QUE ESTA PRUEBA ES DISTINTA DE LA DE AISLAMIENTO
 * --------------------------------------------------
 * En la de aislamiento, las dos filas son de inmobiliarias distintas y las
 * separa la RLS. Aca las dos filas son del MISMO `tenant_id`: la base de datos
 * las deja pasar las dos, y lo que las separa es la policy de cartera.
 *
 * Por eso la prueba tiene que comprobar las DOS cosas por separado:
 *
 *   - Por la API, el corredor no ve la cartera del colega (policy).
 *   - Y un administrador SI ve las dos carteras (filtro, no borrado).
 *
 * Si la segunda comprobara fallara, el filtro seria "nadie ve nada", que es un
 * bug distinto y peor de diagnosticar.
 */
class CarteraDeCorredorTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_corredor_no_ve_las_propiedades_de_otro(): void
    {
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria Compartida');

        $corredorAna = $this->crearUsuario($inmobiliaria, 'corredor', 'Ana');
        $corredorBeto = $this->crearUsuario($inmobiliaria, 'corredor', 'Beto');

        $ofertaDeAna = $this->crearOfertaDeVenta($inmobiliaria, $corredorAna, [
            'direccion' => 'Casa de Ana',
        ]);

        $ofertaDeBeto = $this->crearOfertaDeVenta($inmobiliaria, $corredorBeto, [
            'direccion' => 'Casa de Beto',
        ]);

        // El listado de Ana trae lo suyo y NO lo de Beto.
        $listadoDeAna = $this->actingAs($corredorAna)
            ->getJson('/api/v1/propiedades')
            ->assertOk()
            ->json('datos');

        $ids = array_column($listadoDeAna, 'id');

        $this->assertContains($ofertaDeAna->propiedad_id, $ids);
        $this->assertNotContains(
            $ofertaDeBeto->propiedad_id,
            $ids,
            'Ana no deberia ver la propiedad de Beto.'
        );
    }

    public function test_un_corredor_no_puede_abrir_la_ficha_de_otro(): void
    {
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria Compartida');

        $corredorAna = $this->crearUsuario($inmobiliaria, 'corredor', 'Ana');
        $corredorBeto = $this->crearUsuario($inmobiliaria, 'corredor', 'Beto');

        $ofertaDeBeto = $this->crearOfertaDeVenta($inmobiliaria, $corredorBeto);

        // 404 y no 403: un 403 le confirmaria a Ana que la propiedad existe.
        $this->actingAs($corredorAna)
            ->getJson('/api/v1/propiedades/' . $ofertaDeBeto->propiedad_id)
            ->assertNotFound();
    }

    public function test_un_corredor_no_ve_los_clientes_de_otro(): void
    {
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria Compartida');

        $corredorAna = $this->crearUsuario($inmobiliaria, 'corredor', 'Ana');
        $corredorBeto = $this->crearUsuario($inmobiliaria, 'corredor', 'Beto');

        $clienteDeAna = $this->crearCliente($inmobiliaria, $corredorAna, 'ClienteAna');
        $this->crearCliente($inmobiliaria, $corredorBeto, 'ClienteBeto');

        $listado = $this->actingAs($corredorAna)
            ->getJson('/api/v1/clientes')
            ->assertOk()
            ->json('datos');

        $this->assertCount(1, $listado);
        $this->assertSame($clienteDeAna->id, $listado[0]['id']);

        // Y el detalle de un cliente ajeno tambien es 404.
        $clienteDeBeto = $this->dentroDe($inmobiliaria, fn (): Cliente => Cliente::query()

            ->where('nombres', 'ClienteBeto')
            ->firstOrFail());

        $this->actingAs($corredorAna)
            ->getJson('/api/v1/clientes/' . $clienteDeBeto->id)
            ->assertNotFound();
    }

    public function test_un_corredor_no_ve_el_historial_de_estados_de_otro(): void
    {
        // El historial es informacion de la competencia interna. El corredor
        // nuevo de una cartera traspasada ve el historial viejo (porque es de
        // SU oferta), pero no ve el de las ofertas de otro.
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria Compartida');

        $corredorAna = $this->crearUsuario($inmobiliaria, 'corredor', 'Ana');
        $corredorBeto = $this->crearUsuario($inmobiliaria, 'corredor', 'Beto');

        $ofertaDeBeto = $this->crearOfertaDeVenta($inmobiliaria, $corredorBeto);

        $this->actingAs($corredorAna)
            ->getJson('/api/v1/ofertas/' . $ofertaDeBeto->id . '/historial')
            ->assertNotFound();
    }

    public function test_el_administrador_si_ve_las_dos_carteras(): void
    {
        // El filtro por cartera tiene que ser FILTRAR, no "ocultar". Si el
        // administrador no viera las dos carteras, el filtro estaria roto por
        // el otro extremo y nadie podria auditar nada.
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria Compartida');

        $administrador = $this->crearUsuario($inmobiliaria, 'administrador', 'Jefe');
        $corredorAna = $this->crearUsuario($inmobiliaria, 'corredor', 'Ana');
        $corredorBeto = $this->crearUsuario($inmobiliaria, 'corredor', 'Beto');

        $ofertaDeAna = $this->crearOfertaDeVenta($inmobiliaria, $corredorAna, ['direccion' => 'Casa de Ana']);
        $ofertaDeBeto = $this->crearOfertaDeVenta($inmobiliaria, $corredorBeto, ['direccion' => 'Casa de Beto']);

        $listado = $this->actingAs($administrador)
            ->getJson('/api/v1/propiedades')
            ->assertOk()
            ->json('datos');

        $ids = array_column($listado, 'id');

        $this->assertCount(2, $listado, 'El administrador deberia ver las dos carteras.');
        $this->assertContains($ofertaDeAna->propiedad_id, $ids);
        $this->assertContains($ofertaDeBeto->propiedad_id, $ids);
    }

    public function test_un_corredor_no_puede_filtrar_por_el_corredor_de_otro(): void
    {
        // Si el listado respetara el `corredor_id` que manda la peticion, un
        // corredor podria pedir la cartera de un colega y verla entera. El
        // filtro por corredor se ignora para quien no es administrador.
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria Compartida');

        $corredorAna = $this->crearUsuario($inmobiliaria, 'corredor', 'Ana');
        $corredorBeto = $this->crearUsuario($inmobiliaria, 'corredor', 'Beto');

        $ofertaDeBeto = $this->crearOfertaDeVenta($inmobiliaria, $corredorBeto);

        $listado = $this->actingAs($corredorAna)
            ->getJson('/api/v1/propiedades?corredor_id=' . $corredorBeto->id)
            ->assertOk()
            ->json('datos');

        $this->assertNotContains(
            $ofertaDeBeto->propiedad_id,
            array_column($listado, 'id'),
            'Pedir la cartera de otro no deberia funcionar ni filtrando por parametro.'
        );
    }

    public function test_una_propiedad_sin_corredor_solo_la_ve_el_administrador(): void
    {
        // Decision explicita, no un olvido: una propiedad sin corredor asignado
        // es la cartera sin dueno del jefe, no la de todos. Ver PolicyDeCartera.
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria Compartida');

        $administrador = $this->crearUsuario($inmobiliaria, 'administrador', 'Jefe');
        $corredorAna = $this->crearUsuario($inmobiliaria, 'corredor', 'Ana');

        $ofertaSinDueno = $this->crearOfertaDeVenta($inmobiliaria, null, [
            'direccion' => 'Propiedad sin corredor',
        ]);

        $listadoDeAna = $this->actingAs($corredorAna)
            ->getJson('/api/v1/propiedades')
            ->assertOk()
            ->json('datos');

        $this->assertNotContains($ofertaSinDueno->propiedad_id, array_column($listadoDeAna, 'id'));

        $listadoDelJefe = $this->actingAs($administrador)
            ->getJson('/api/v1/propiedades')
            ->assertOk()
            ->json('datos');

        $this->assertContains($ofertaSinDueno->propiedad_id, array_column($listadoDelJefe, 'id'));
    }
}
