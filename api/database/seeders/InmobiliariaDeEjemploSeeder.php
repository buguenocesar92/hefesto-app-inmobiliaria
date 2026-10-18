<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EstadoComercial;
use App\Models\Cliente;
use App\Models\Estado;
use App\Models\Oferta;
use App\Models\Propiedad;
use App\Models\Solicitud;
use App\Models\Usuario;
use App\Models\Visita;
use App\Services\Estados\RegistradorDeCambioEstado;
use App\Services\Operaciones\CalculadoraDeComision;
use App\Services\Tenancy\ContextoTenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inmobiliaria de ejemplo, con propiedades en distintos estados, clientes,
 * visitas y una reserva.
 *
 * PARA QUE SIRVE
 * --------------
 * Deja la aplicacion en un estado en que se puede abrir el tablero y comprobar
 * que el producto dice algo:
 *
 *   - admin@sur.test        administracion
 *   - ana@sur.test          cartera de Ana
 *   - beto@sur.test         cartera de Beto
 *
 * Y sobre todo deja historial REAL: hay propiedades con reservas que duraron
 * 6 dias, una que lleva 210 dias reservada (que es la que tiene que aparecer
 * arriba en el tablero) y una que estuvo reservada y se liberada sola. Sin eso
 * el tablero aparece vacio y no se puede juzgar la pantalla.
 *
 * POR QUE TODAS LAS ESCRITURAS VAN DENTRO DE `ejecutarAislado`
 * -----------------------------------------------------------
 * Las tablas de negocio tienen Row Level Security forzado, asi que una escritura
 * sin contexto de inmobiliaria NO se guarda: falla. La semilla no es una
 * excepcion.
 */
class InmobiliariaDeEjemploSeeder extends Seeder
{
    public function run(): void
    {
        // `inmobiliarias` no tiene RLS: se puede leer y escribir sin contexto.
        $inmobiliaria = Inmobiliaria::query()->updateOrCreate(
            ['correlacion_identidad' => 'sur'],
            [
                'slug' => 'inmobiliaria-sur',
                'nombre' => 'Inmobiliaria Sur',
                'razon_social' => 'Inmobiliaria Sur Ltda.',
                'rut' => '76.123.456-7',
                'direccion' => 'Av. Providencia 1234, of. 502, Providencia, Santiago',
                'telefono' => '+56 2 2987 6543',
                'correo' => 'contacto@inmobiliariasur.test',
                'zona_horaria' => 'America/Santiago',
                'activo' => true,
            ]
        );

        app(ContextoTenant::class)->ejecutarAislado((string) $inmobiliaria->id, function () use ($inmobiliaria): void {
            $estados = $this->estados($inmobiliaria);
            $corredores = $this->corredores($inmobiliaria);

            $propiedades = $this->propiedades($inmobiliaria, $corredores);
            $ofertas = $this->ofertas($inmobiliaria, $propiedades, $corredores, $estados);

            $this->historialReal($ofertas, $estados, $corredores);

            $clientes = $this->clientes($inmobiliaria, $corredores);
            $this->solicitudes($inmobiliaria, $clientes, $propiedades, $corredores);
            $this->visitas($inmobiliaria, $clientes, $propiedades, $corredores);
            $this->reservaVigente($inmobiliaria, $ofertas, $clientes, $corredores);
        });
    }

    /**
     * Agrega el `tenant_id` del contexto actual a los atributos de alta.
     *
     * @param  array<string, mixed>  $atributos
     * @return array<string, mixed>
     */
    private function con(array $atributos): array
    {
        return array_merge($atributos, [
            'tenant_id' => app(ContextoTenant::class)->inmobiliariaIdEnConexion(),
        ]);
    }

    /**
     * Los estados de la inmobiliaria, incluidos los que el motor necesita.
     *
     * Se crean con los NOMBRES que usa una inmobiliaria chilena, no con los
     * nombres internos del motor. A proposito: asi se comprueba de entrada que
     * la liberacion automatica resuelve el estado por `clave_sistema` y no por
     * el texto.
     *
     * @return array<string, Estado>
     */
    private function estados(Inmobiliaria $inmobiliaria): array
    {
        $definiciones = [
            // clave_sistema => [nombre, tipo, color, inicial, final]
            EstadoComercial::Disponible->value => ['En oferta', 'ambas', '#2f6f4e', true, false],
            EstadoComercial::Reservada->value => ['Reservada', 'ambas', '#b8860b', false, false],
            EstadoComercial::Escriturado->value => ['Escriturado', 'venta', '#4a5568', false, true],
            EstadoComercial::Arrendada->value => ['Arrendada', 'arriendo', '#2b6cb0', false, true],
        ];

        $estados = [];

        $posicion = 1;

        foreach ($definiciones as $clave => [$nombre, $tipo, $color, $inicial, $final]) {
            $estados[$clave] = Estado::query()->create($this->con([
                'nombre' => $nombre,
                'descripcion' => null,
                'clave_sistema' => $clave,
                'tipo_operacion' => $tipo,
                'es_inicial' => $inicial,
                'es_final' => $final,
                'posicion' => $posicion++,
                'color' => $color,
                'activo' => true,
            ]));
        }

        // Un estado SOLO de configuracion, sin clave de sistema: existe para
        // probar que el motor no depende de el y que el administrador puede
        // agregar los suyos.
        $estados['evaluacion'] = Estado::query()->create($this->con([
            'nombre' => 'En evaluacion del propietario',
            'descripcion' => 'El propietario esta evaluando una oferta. No esta en el mercado.',
            'clave_sistema' => null,
            'tipo_operacion' => 'ambas',
            'es_inicial' => false,
            'es_final' => false,
            'posicion' => $posicion++,
            'color' => '#8a5a2b',
            'activo' => true,
        ]));

        return $estados;
    }

    /**
     * @return array<string, Usuario>
     */
    private function corredores(Inmobiliaria $inmobiliaria): array
    {
        $admin = Usuario::query()->create($this->con([
            'correlacion_identidad' => 'HefestoInmobiliaria:sur:admin@sur.test',
            'nombre' => 'Jefe de Inmobiliaria Sur',
            'correo' => 'admin@sur.test',
            'rol' => 'administrador',
            'activo' => true,
        ]));

        $ana = Usuario::query()->create($this->con([
            'correlacion_identidad' => 'HefestoInmobiliaria:sur:ana@sur.test',
            'nombre' => 'Ana Rojas',
            'correo' => 'ana@sur.test',
            'rol' => 'corredor',
            'activo' => true,
        ]));

        $beto = Usuario::query()->create($this->con([
            'correlacion_identidad' => 'HefestoInmobiliaria:sur:beto@sur.test',
            'nombre' => 'Beto Soto',
            'correo' => 'beto@sur.test',
            'rol' => 'corredor',
            'activo' => true,
        ]));

        return ['admin' => $admin, 'ana' => $ana, 'beto' => $beto];
    }

    /**
     * Propiedades en distintos lipos, zonas y precios.
     *
     * @param  array<string, Usuario>  $corredores
     * @return array<string, Propiedad>
     */
    private function propiedades(Inmobiliaria $inmobiliaria, array $corredores): array
    {
        $fichas = [
            'alto' => [
                'direccion' => 'Av. Los Capitanes 4455, depto 1203, Las Condes',
                'comuna' => 'Las Condes',
                'tipo_propiedad' => 'departamento',
                'dormitorios' => 4,
                'banos' => 3,
                'estacionamientos' => 2,
                'superficie_m2' => 142.50,
                'anio_construccion' => 2015,
                'piso' => '12',
                'departamento' => '1203',
                'valor_venta' => 189_000_000,
                'valor_arriendo' => null,
                'corredor_id' => $corredores['ana']->id,
                'descripcion' => 'Depto de dos pisos con vista al Parque Bicentenario, con suite principal y escritorio.',
            ],
            'medio' => [
                'direccion' => 'Calle Rosamel 1170, depto 42, La Florida',
                'comuna' => 'La Florida',
                'tipo_propiedad' => 'departamento',
                'dormitorios' => 3,
                'banos' => 2,
                'estacionamientos' => 1,
                'superficie_m2' => 88.00,
                'anio_construccion' => 1998,
                'piso' => '4',
                'departamento' => '42',
                'valor_venta' => 96_000_000,
                'valor_arriendo' => 780_000,
                'corredor_id' => $corredores['ana']->id,
                'descripcion' => 'Departamento familiar en edificio con piscina y areas verdes.',
            ],
            'departamentoArriendo' => [
                'direccion' => 'Calle Schoenborn 340, depto 71, Conchalaba',
                'comuna' => 'Conchalaba',
                'tipo_propiedad' => 'departamento',
                'dormitorios' => 2,
                'banos' => 1,
                'estacionamientos' => 1,
                'superficie_m2' => 64.00,
                'anio_construccion' => 2005,
                'piso' => '7',
                'departamento' => '71',
                'valor_venta' => null,
                'valor_arriendo' => 520_000,
                'amoblado' => true,
                'corredor_id' => $corredores['beto']->id,
                'descripcion' => 'Amoblado, listo para arrendar, a metros del metro.',
            ],
            'casa' => [
                'direccion' => 'Camino El Alba 8900, Las Condes',
                'comuna' => 'Las Condes',
                'tipo_propiedad' => 'casa',
                'dormitorios' => 5,
                'banos' => 4,
                'estacionamientos' => 3,
                'superficie_m2' => 320.00,
                'anio_construccion' => 2009,
                'valor_venta' => 240_000_000,
                'valor_arriendo' => null,
                'corredor_id' => $corredores['beto']->id,
                'descripcion' => 'Casa en condominio cerrado, con jardin, quincho y reja electrica.',
            ],
            'oficina' => [
                'direccion' => 'Av. Providencia 1940, of. 803, Providencia',
                'comuna' => 'Providencia',
                'tipo_propiedad' => 'oficina',
                'dormitorios' => null,
                'banos' => 2,
                'estacionamientos' => 2,
                'superficie_m2' => 110.00,
                'anio_construccion' => 2012,
                'piso' => '8',
                'departamento' => '803',
                'valor_venta' => 78_000_000,
                'valor_arriendo' => 2_400_000,
                'corredor_id' => $corredores['ana']->id,
                'descripcion' => 'Oficina amoblada, con dos estacionamientos y acceso a ascensor de carga.',
            ],
            'local' => [
                'direccion' => 'Av. Vicuña Mackenna 6200, local 3, La Florida',
                'comuna' => 'La Florida',
                'tipo_propiedad' => 'local',
                'superficie_m2' => 180.00,
                'anio_construccion' => 1990,
                'valor_venta' => 58_000_000,
                'valor_arriendo' => 1_600_000,
                'corredor_id' => $corredores['beto']->id,
                'descripcion' => 'Local comercial con frente de 8 metros y patente de comida.',
            ],
        ];

        $propiedades = [];

        foreach ($fichas as $clave => $ficha) {
            $propiedades[$clave] = Propiedad::query()->create($this->con($ficha));
        }

        return $propiedades;
    }

    /**
     * Una oferta por tipo de operacion disponible para cada propiedad.
     *
     * @param  array<string, Propiedad>  $propiedades
     * @param  array<string, Usuario>  $corredores
     * @param  array<string, Estado>  $estados
     * @return array<string, Oferta>
     */
    private function ofertas(Inmobiliaria $inmobiliaria, array $propiedades, array $corredores, array $estados): array
    {
        $ofertas = [];

        // Que tipos tiene cada propiedad depende de si tiene precio de lista
        // para ese tipo. Es la misma regla del negocio: una propiedad sin
        // precio de arriendo no tiene oferta de arriendo.
        $tiposPorPropiedad = [
            'alto' => ['venta'],
            'medio' => ['venta', 'arriendo'],
            'departamentoArriendo' => ['arriendo'],
            'casa' => ['venta'],
            'oficina' => ['venta', 'arriendo'],
            'local' => ['venta', 'arriendo'],
        ];

        $registrador = app(RegistradorDeCambioEstado::class);

        foreach ($tiposPorPropiedad as $clavePropiedad => $tipos) {
            foreach ($tipos as $tipo) {
                $propiedad = $propiedades[$clavePropiedad];
                $estadoInicial = $estados[EstadoComercial::Disponible->value];

                $oferta = Oferta::query()->create($this->con([
                    'propiedad_id' => $propiedad->id,
                    'corredor_id' => $propiedad->corredor_id,
                    'tipo_operacion' => $tipo,
                    'estado_id' => $estadoInicial->id,
                    'estado_desde' => now(),
                    'valor_lista' => $tipo === 'venta' ? $propiedad->valor_venta : $propiedad->valor_arriendo,
                    'visible_en_portal' => true,
                    'activa' => true,
                ]));

                $registrador->registrarPrimerTramo($oferta, $estadoInicial);

                $ofertas[$clavePropiedad . '_' . $tipo] = $oferta->refresh();
            }
        }

        return $ofertas;
    }

    /**
     * Historial con duraciones reales, para que el tablero tenga que decir algo.
     *
     * @param  array<string, Oferta>  $ofertas
     * @param  array<string, Estado>  $estados
     * @param  array<string, Usuario>  $corredores
     */
    private function historialReal(array $ofertas, array $estados, array $corredores): void
    {
        $registrador = app(RegistradorDeCambioEstado::class);

        $reservada = $estados[EstadoComercial::Reservada->value];
        $disponible = $estados[EstadoComercial::Disponible->value];
        $escriturado = $estados[EstadoComercial::Escriturado->value];
        $arrendada = $estados[EstadoComercial::Arrendada->value];

        // 1. Una venta que se CERRO: 6 dias reservada y luego escriturada.
        $this->pasarPor(
            $ofertas['casa_venta'],
            $reservada,
            Carbon::now()->subDays(84),
        );

        $this->cerrarEn($ofertas['casa_venta'], $escriturado, 'Escritura firmada en notaria');

        // 2. Una reserva que se cayo: 12 dias y de vuelta al mercado.
        $this->pasarPor(
            $ofertas['alto_venta'],
            $reservada,
            Carbon::now()->subDays(30),
        );

        $this->cerrarEn($ofertas['alto_venta'], $disponible, 'El comprador eligio otra propiedad');

        // 3. LA RESERVA COLGADA: 210 dias. Esta es la fila que tiene que salir
        //    arriba en el tablero, y es la que justifica el producto.
        $this->pasarPor(
            $ofertas['oficina_venta'],
            $reservada,
            Carbon::now()->subDays(210),
        );

        // 4. Un arriendo que va bien: 5 meses arrendado.
        $this->pasarPor(
            $ofertas['departamentoArriendo_arriendo'],
            $arrendada,
            Carbon::now()->subDays(150),
        );

        // 5. Una propiedad que el propietario retiro del mercado.
        $this->pasarPor(
            $ofertas['local_venta'],
            $estados['evaluacion'],
            Carbon::now()->subDays(9),
        );
    }

    /**
     * Mueve la oferta a un estado, corriendo el inicio del tramo.
     *
     * El `desde` se corre DESPUES de registrar y ANTES de cerrar, mientras el
     * tramo esta abierto: un tramo cerrado no se puede mover. Asi la duracion
     * del tramo anterior queda medida y el tablero tiene historia real.
     *
     * @param  Estado  $estado
     */
    private function pasarPor(Oferta $oferta, Estado $estado, Carbon $desde): void
    {
        app(RegistradorDeCambioEstado::class)->registrar(
            oferta: $oferta->refresh(),
            estadoNuevo: $estado,
            motivo: 'Cambio de estado en la semilla',
            usuarioId: null,
        );

        // Se corre el tramo recien abierto para que su duracion sea la
        // buscada. El disparador lo permite porque el tramo sigue abierto.
        DB::table('cambios_estado')
            ->where('oferta_id', $oferta->id)
            ->whereNull('hasta')
            ->update(['desde' => $desde]);

        $oferta->refresh()->forceFill(['estado_desde' => $desde])->save();
    }

    /**
     * Cierra el tramo abierto dejando el estado final.
     */
    private function cerrarEn(Oferta $oferta, Estado $estado, string $motivo): void
    {
        app(RegistradorDeCambioEstado::class)->registrar(
            oferta: $oferta->refresh(),
            estadoNuevo: $estado,
            motivo: $motivo,
            usuarioId: null,
        );
    }

    /**
     * @param  array<string, Usuario>  $corredores
     * @return array<string, Cliente>
     */
    private function clientes(Inmobiliaria $inmobiliaria, array $corredores): array
    {
        $fichas = [
            'maria' => [
                'nombres' => 'Maria',
                'apellidos' => 'Fernandez Lopez',
                'telefono' => '+56 9 8877 6655',
                'correo' => 'maria.fernandez@example.test',
                'corredor_id' => $corredores['ana']->id,
                'notas' => 'Busca para arriendo, decide con su pareja.',
            ],
            'jose' => [
                'nombres' => 'Jose',
                'apellidos' => 'Muniz Carter',
                'telefono' => '+56 9 5544 3322',
                'correo' => 'jose.muniz@example.test',
                'corredor_id' => $corredores['beto']->id,
                'notas' => 'Inversionista. Le interesa el rendimiento mensual.',
            ],
            'lucia' => [
                'nombres' => 'Lucia',
                'apellidos' => 'Perez Nunez',
                'telefono' => '+56 9 2233 1144',
                'correo' => 'lucia.perez@example.test',
                'corredor_id' => $corredores['ana']->id,
                'notas' => null,
            ],
            'camila' => [
                'nombres' => 'Camila',
                'apellidos' => 'Tapia Rojas',
                'telefono' => '+56 9 7788 4455',
                'correo' => 'camila.tapia@example.test',
                'corredor_id' => $corredores['beto']->id,
                'notas' => null,
            ],
        ];

        $clientes = [];

        foreach ($fichas as $clave => $ficha) {
            $clientes[$clave] = Cliente::query()->create($this->con(array_merge($ficha, [
                'consentimiento_contacto' => true,
                'consentimiento_fecha' => now()->subMonths(2),
                'activo' => true,
            ])));
        }

        return $clientes;
    }

    /**
     * Solicitudes de los clientes, con propiedades propuestas.
     *
     * @param  array<string, Cliente>  $clientes
     * @param  array<string, Propiedad>  $propiedades
     * @param  array<string, Usuario>  $corredores
     */
    private function solicitudes(Inmobiliaria $inmobiliaria, array $clientes, array $propiedades, array $corredores): void
    {
        $definiciones = [
            // Maria tiene DOS solicitudes activas a la vez: una de arriendo y
            // una de venta. Es el caso que rompe el modelo de "un cliente, una
            // ficha de busqueda".
            'maria_arriendo' => [
                'cliente' => $clientes['maria'],
                'tipo_operacion' => 'arriendo',
                'comuna' => 'La Florida',
                'zona' => 'Sector sur',
                'presupuesto_max' => 800_000,
                'dormitorios_min' => 3,
                'banos_min' => 2,
                'estado' => 'activa',
                'propiedades' => ['medio', 'oficina'],
            ],
            'maria_venta' => [
                'cliente' => $clientes['maria'],
                'tipo_operacion' => 'venta',
                'comuna' => 'Las Condes',
                'presupuesto_max' => 150_000_000,
                'dormitorios_min' => 3,
                'estado' => 'activa',
                'propiedades' => ['alto', 'casa'],
            ],
            'jose_arriendo' => [
                'cliente' => $clientes['jose'],
                'tipo_operacion' => 'arriendo',
                'comuna' => 'Conchalaba',
                'presupuesto_max' => 550_000,
                'dormitorios_min' => 2,
                'estado' => 'activa',
                'propiedades' => ['departamentoArriendo'],
            ],
            'camila_venta' => [
                'cliente' => $clientes['camila'],
                'tipo_operacion' => 'venta',
                'comuna' => 'Providencia',
                'presupuesto_max' => 90_000_000,
                'estado' => 'pausada',
                'propiedades' => ['oficina'],
            ],
        ];

        foreach ($definiciones as $definicion) {
            $solicitud = Solicitud::query()->create($this->con([
                'cliente_id' => $definicion['cliente']->id,
                'corredor_id' => $definicion['cliente']->corredor_id,
                'tipo_operacion' => $definicion['tipo_operacion'],
                'comuna' => $definicion['comuna'] ?? null,
                'zona' => $definicion['zona'] ?? null,
                'presupuesto_max' => $definicion['presupuesto_max'] ?? null,
                'dormitorios_min' => $definicion['dormitorios_min'] ?? null,
                'banos_min' => $definicion['banos_min'] ?? null,
                'estado' => $definicion['estado'],
                'notas' => null,
            ]));

            foreach ($definicion['propiedades'] as $clave) {
                DB::table('solicitud_propiedad')->insert([
                    'id' => (string) Str::uuid(),
                    'tenant_id' => app(ContextoTenant::class)->inmobiliariaIdEnConexion(),
                    'solicitud_id' => $solicitud->id,
                    'propiedad_id' => $propiedades[$clave]->id,
                    'usuario_id' => $definicion['cliente']->corredor_id,
                    'propuesta_en' => now()->subDays(12),
                    'estado' => 'pendiente',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * @param  array<string, Cliente>  $clientes
     * @param  array<string, Propiedad>  $propiedades
     * @param  array<string, Usuario>  $corredores
     */
    private function visitas(Inmobiliaria $inmobiliaria, array $clientes, array $propiedades, array $corredores): void
    {
        $visitas = [
            ['realizada', 'muy_interesado', true, 'Quiere volver el sabado con su pareja.'],
            ['realizada', 'indeciso', false, 'Le gusto el barrio, pero dice que le da miedo el precio.'],
            ['no_se_presento', null, false, 'No llego. Se ofrecio reagendar por WhatsApp.'],
            ['agendada', null, false, null],
            ['realizada', 'descartado', false, 'La zona no le sirvio, busca algo mas al sur.'],
        ];

        $parejas = [
            [$clientes['maria'], $propiedades['medio']],
            [$clientes['maria'], $propiedades['alto']],
            [$clientes['lucia'], $propiedades['oficina']],
            [$clientes['jose'], $propiedades['departamentoArriendo']],
            [$clientes['camila'], $propiedades['casa']],
        ];

        $inicio = Carbon::now()->subDays(3)->setTime(11, 0);

        foreach ($visitas as $indice => [$estado, $resultado, $ajuste, $notas]) {
            [$cliente, $propiedad] = $parejas[$indice];

            Visita::query()->create($this->con([
                'propiedad_id' => $propiedad->id,
                'cliente_id' => $cliente->id,
                'corredor_id' => $propiedad->corredor_id ?? $corredores['admin']->id,
                'inicio' => $inicio->copy()->addDays($indice),
                'duracion_minutos' => 30,
                'estado' => $estado,
                'resultado' => $resultado,
                'ofrecio_ajuste' => $ajuste,
                'notas' => $notas,
            ]));
        }
    }

    /**
     * Una reserva vigente, que vence en 9 dias.
     *
     * Se crea sobre la oferta que quedo RESERVADA hace 210 dias, que es la
     * misma que esta en la semilla del historial. Asi el tablero muestra la
     * reserva colgada con su operacion y su fecha de vencimiento.
     *
     * @param  array<string, Oferta>  $ofertas
     * @param  array<string, Cliente>  $clientes
     * @param  array<string, Usuario>  $corredores
     */
    private function reservaVigente(Inmobiliaria $inmobiliaria, array $ofertas, array $clientes, array $corredores): void
    {
        $oferta = $ofertas['oficina_venta'];

        $pactado = 72_000_000;
        $porcentaje = (float) config('inmobiliaria.comision.porcentaje_venta', 3.0);

        /*
     * EN ESTA SEMILLA NO SE VALIDA CONTRA EL ALTA
     * -------------------------------------------
     * El tope de INMOBILIARIA_RESERVA_DIAS_MAXIMOS (30 dias) se aplica en
     * `CrearOperacionRequest`, que es el camino de una peticion real. Esta
     * semilla escribe directo a la base a proposito, para poder dejar en el
     * historial la reserva colgada de 210 dias que es justamente la fila que
     * el tablero tiene que sacar arriba.
     *
     * Que la base no imponga el tope es correcto: la base no sabe cuando se
     * creo la operacion con la hora exacta, y un tope es una REGLA DE NEGOCIO,
     * no una restriccion de integridad. Lo que la base si impone es que una
     * reserva tenga `expira_en`, que es la condicion para que la liberacion
     * automatica tenga algo que mirar.
     */
        \App\Models\Operacion::query()->create($this->con([
            'oferta_id' => $oferta->id,
            'propiedad_id' => $oferta->propiedad_id,
            'cliente_id' => $clientes['camila']->id,
            'corredor_id' => $oferta->corredor_id,
            'tipo' => 'reserva',

            // Pactado por DEBAJO de la lista (78 millones). La comision sale de
            // aca, no de los 78.
            'valor_pactado' => $pactado,
            'valor_lista_oferta' => $oferta->valor_lista,
            'porcentaje_comision' => $porcentaje,
            'comision_monto' => CalculadoraDeComision::calcular($pactado, $porcentaje),
            'modalidad_comision' => 'porcentaje',
            'comision_adicional' => null,

            'creada_en' => Carbon::now()->subDays(210),
            'expira_en' => Carbon::now()->addDays(9),

            'renovaciones' => 0,
            'estado_contrato' => 'vigente',
        ]));
    }
}