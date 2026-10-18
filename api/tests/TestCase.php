<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\EstadoComercial;
use App\Models\Cliente;
use App\Models\Estado;
use App\Models\Inmobiliaria;
use App\Models\Oferta;
use App\Models\Propiedad;
use App\Models\Usuario;
use App\Services\Estados\RegistradorDeCambioEstado;
use App\Services\Tenancy\ContextoTenant;
use Closure;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base de las pruebas de Hefesto Inmobiliaria.
 *
 * POR QUE TODAS LAS PRUEBAS NECESITAN `dentroDe`
 * ----------------------------------------------
 * Las tablas de negocio tienen Row Level Security FORZADO, para que las
 * politicas apliquen tambien al dueno de la tabla (que es el usuario con el
 * que se conecta Laravel). Consecuencia: si una prueba crea filas sin declarar
 * la inmobiliaria a la que pertenecen, la base las rechaza.
 *
 * Eso no es un estorbo para probar, es parte de lo que se prueba. `dentroDe` es
 * el mismo camino que usa la peticion real: una transaccion con el contexto
 * fijado.
 *
 * PostgreSQL y no SQLite: en SQLite estas pruebas no comprobarian nada, porque
 * no hay RLS. Ver phpunit.xml.
 */
abstract class TestCase extends BaseTestCase
{
    /**
     * Ejecuta una operacion dentro del contexto de una inmobiliaria.
     *
     * @template T
     *
     * @param  Closure(): T  $operacion
     * @return T
     */
    protected function dentroDe(Usuario|Inmobiliaria $contexto, Closure $operacion): mixed
    {
        $inmobiliariaId = $contexto instanceof Usuario
            ? (string) $contexto->tenant_id
            : (string) $contexto->id;

        return app(ContextoTenant::class)->ejecutarAislado($inmobiliariaId, $operacion);
    }

    /**
     * Una inmobiliaria con sus estados minimos.
     *
     * Los estados que el motor necesita (disponible, reservada, escriturado,
     * arrendada) se crean SIEMPRE: sin ellos, el registro de un cambio de
     * estado falla, y las pruebas que miden permanencia no tendrian contra que
     * medir.
     */
    protected function crearInmobiliaria(string $nombre = 'Inmobiliaria de prueba'): Inmobiliaria
    {
        $inmobiliaria = Inmobiliaria::query()->create([
            'correlacion_identidad' => 'prueba-' . \Illuminate\Support\Str::slug($nombre) . '-' . substr(
                (string) \Illuminate\Support\Str::uuid(),
                0,
                6
            ),
            'slug' => \Illuminate\Support\Str::slug($nombre) . '-' . substr((string) \Illuminate\Support\Str::uuid(), 0, 6),
            'nombre' => $nombre,
            'activo' => true,
        ]);

        $this->crearEstados($inmobiliaria);

        return $inmobiliaria;
    }

    /**
     * Los estados que el motor necesita, para una inmobiliaria.
     *
     * @return array<string, Estado>
     */
    protected function crearEstados(Inmobiliaria $inmobiliaria): array
    {
        $definiciones = [
            EstadoComercial::Disponible->value => [
                'nombre' => 'Disponible',
                'tipo_operacion' => 'ambas',
                'es_inicial' => true,
                'posicion' => 1,
                'color' => '#2f6f4e',
            ],
            EstadoComercial::Reservada->value => [
                'nombre' => 'Reservada',
                'tipo_operacion' => 'ambas',
                'es_inicial' => false,
                'posicion' => 2,
                'color' => '#b8860b',
            ],
            EstadoComercial::Escriturado->value => [
                'nombre' => 'Escriturado',
                'tipo_operacion' => 'venta',
                'es_inicial' => false,
                'es_final' => true,
                'posicion' => 3,
                'color' => '#4a5568',
            ],
            EstadoComercial::Arrendada->value => [
                'nombre' => 'Arrendada',
                'tipo_operacion' => 'arriendo',
                'es_inicial' => false,
                'es_final' => true,
                'posicion' => 4,
                'color' => '#2b6cb0',
            ],
        ];

        $estados = [];

        foreach ($definiciones as $clave => $datos) {
            $estados[$clave] = Estado::query()->create(array_merge($datos, [
                'tenant_id' => $inmobiliaria->id,
                'clave_sistema' => $clave,
            ]));
        }

        return $estados;
    }

    /**
     * Crea un usuario en una inmobiliaria.
     */
    protected function crearUsuario(
        Inmobiliaria $inmobiliaria,
        string $rol = 'corredor',
        string $nombre = 'Persona de prueba',
    ): Usuario {
        return $this->dentroDe($inmobiliaria, fn (): Usuario => Usuario::query()->create([
            'tenant_id' => $inmobiliaria->id,
            'correlacion_identidad' => 'prueba:' . $inmobiliaria->correlacion_identidad . ':' . $nombre,
            'nombre' => $nombre,
            'correo' => \Illuminate\Support\Str::slug($nombre) . '@prueba.test',
            'rol' => $rol,
            'activo' => true,
        ]));
    }

    /**
     * Crea una propiedad y su oferta de venta, con el historial arrancado.
     *
     * Devuelve la oferta, que es donde vive el estado.
     *
     * @param  array<string, mixed>  $atributos
     */
    protected function crearOfertaDeVenta(
        Inmobiliaria $inmobiliaria,
        ?Usuario $corredor = null,
        array $atributos = [],
    ): Oferta {
        return $this->dentroDe($inmobiliaria, function () use ($inmobiliaria, $corredor, $atributos): Oferta {
            $propiedad = Propiedad::query()->create(array_merge([
                'tenant_id' => $inmobiliaria->id,
                'corredor_id' => $corredor?->id,
                'direccion' => 'Direccion de prueba 123',
                'comuna' => 'La Florida',
                'tipo_propiedad' => 'departamento',
                'dormitorios' => 3,
                'banos' => 2,
                'valor_venta' => 120_000_000,
                'activa' => true,
            ], $atributos));

            $disponible = Estado::query()
                ->where('tenant_id' => $inmobiliaria->id)
                ->where('clave_sistema', EstadoComercial::Disponible->value)
                ->firstOrFail();

            $oferta = Oferta::query()->create([
                'tenant_id' => $inmobiliaria->id,
                'propiedad_id' => $propiedad->id,
                'corredor_id' => $corredor?->id,
                'tipo_operacion' => 'venta',
                'estado_id' => $disponible->id,
                'estado_desde' => now(),
                'valor_lista' => $propiedad->valor_venta,
                'activa' => true,
            ]);

            app(RegistradorDeCambioEstado::class)->registrarPrimerTramo($oferta, $disponible);

            return $oferta->refresh();
        });
    }

    /**
     * Crea un cliente.
     */
    protected function crearCliente(
        Inmobiliaria $inmobiliaria,
        ?Usuario $corredor = null,
        string $nombre = 'Cliente de prueba',
    ): Cliente {
        return $this->dentroDe($inmobiliaria, fn (): Cliente => Cliente::query()->create([
            'tenant_id' => $inmobiliaria->id,
            'corredor_id' => $corredor?->id,
            'nombres' => $nombre,
            'apellidos' => 'Apellido',
            'telefono' => '+56 9 1111 2222',
            'correo' => \Illuminate\Support\Str::slug($nombre) . '@prueba.test',
            'consentimiento_contacto' => true,
            'consentimiento_fecha' => now(),
            'activo' => true,
        ]));
    }
}