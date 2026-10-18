<?php

declare(strict_types=1);

namespace App\Services\Estados;

use App\Enums\TipoOperacion;
use App\Models\CambioEstado;
use App\Models\Estado;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * Medidor de permanencia: convierte el historial en la cifra que el cliente
 * compra.
 *
 * QUE ES ESTO
 * -----------
 * "Una propiedad que estuvo reservada ocho meses en el papel es una propiedad
 * que estuvo ocho meses sin vender." Esta clase existe para sacar esa cuenta, y
 * es la razon por la que el historial guarda la duracion de cada tramo.
 *
 * QUE NO HACE, Y POR QUE IMPORTA
 * ------------------------------
 * No calcula el PROMEDIO y ya. Un promedio solo engana: si cinco reservas
 * duraron 10 dias y una duro 400, el promedio da 78 y no describe nada. Por eso
 * devuelve los tres numeros que cuentan la historia:
 *
 *   - `promedio_dias`: para comparar car'steras del mismo tamano.
 *   - `mediana_dias`: el caso tipico, el que no se distorce con un caso raro.
 *   - `p90_dias`: el mal caso. Noventa de cada cien reservas se cerraron antes
 *     de este numero, asi que si algo se pasa del p90 de tu propia historia,
 *     esa reserva es anormal para tu negocio y no para el mercado.
 *
 * Y devuelve ademas `en_curso`: las propiedades que HOY estan en ese estado y
 * hace cuanto. Ese numero es el que hace saltar lacampana: una reserva con 210
 * dias abiertos es, casi siempre, una reserva que nadie va a confirmar.
 *
 * QUE MIDE EXACTAMENTE
 * --------------------
 * Solo tramos CERRADOS para los estadisticos. Incluir los tramos abiertos
 * Sesgaria el promedio hacia arriba de forma systematico, porque un tramo
 * abierto siempre tiene duracion >= su duracion final. Los tramos abiertos se
 * cuentan aparte, en `en_curso`.
 */
class MedidorDePermanencia
{
    /**
     * Permanencia por estado, para un tipo de operacion.
     *
     * @return Collection<int, array{
     *     estado_id: string,
     *     nombre: string,
     *     color: string,
     *     tramos_cerrados: int,
     *     promedio_dias: float|null,
     *     mediana_dias: float|null,
     *     p90_dias: float|null,
     *     maximo_dias: int|null,
     *     en_curso: int,
     *     dias_del_mas_antiguo: int|null
     * }>
     */
    public function porEstado(
        string $tenantId,
        TipoOperacion $tipo,
        ?string $comuna = null,
    ): Collection {
        $base = $this->tramosDe($tenantId, $tipo, $comuna);

        $idsDeEstados = $base
            ->flatMap(fn (array $t): array => [$t['estado_nuevo_id'], $t['estado_anterior_id']])
            ->filter()
            ->unique()
            ->values();

        // Los estados sin historial tambien tienen que aparecer, con todo en
        // cero. Un tablero que esconde el estado "escriturado" porque todavia
        // nadie llego a el hace creer que el estado no existe.
        $estados = Estado::query()
            ->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q
                ->where('tipo_operacion', $tipo->value)
                ->orWhere('tipo_operacion', 'ambas'))
            ->orderBy('posicion')
            ->get()
            ->keyBy('id');

        foreach ($idsDeEstados as $id) {
            if (! $estados->has($id)) {
                $estados->put($id, new Estado(['id' => $id, 'nombre' => '(estado eliminado)', 'color' => '#888888']));
            }
        }

        $cerrados = $base->whereNotNull('duracion_dias');
        $abiertos = $base->whereNull('duracion_dias');

        return $estados->map(function (Estado $estado) use ($cerrados, $abiertos): array {
            $duraciones = $cerrados
                ->where('estado_nuevo_id', $estado->id)
                ->pluck('duracion_dias')
                ->map(fn ($d): int => (int) $d)
                ->sort()
                ->values();

            $enCurso = $abiertos->where('estado_nuevo_id', $estado->id);

            return [
                'estado_id' => $estado->id,
                'nombre' => $estado->nombre,
                'color' => $estado->color,
                'tramos_cerrados' => $duraciones->count(),

                // null en vez de 0 cuando no hay datos: "no hay casos" y "los
                // casos duran cero" son cosas distintas y el tablero las tiene
                // que pintar distinto.
                'promedio_dias' => $this->promedio($duraciones),
                'mediana_dias' => $this->percentil($duraciones, 0.50),
                'p90_dias' => $this->percentil($duraciones, 0.90),
                'maximo_dias' => $duraciones->isEmpty() ? null : (int) $duraciones->max(),

                'en_curso' => $enCurso->count(),
                'dias_del_mas_antiguo' => $this->diasDelMasAntiguo($enCurso),
            ];
        })->values();
    }

    /**
     * Cuanto lleva hoy cada propiedad en su estado actual.
     *
     * Es la vista de trabajo del dia: las reservas abiertas ordenadas de mas
     * antigua a mas reciente. La primera fila es, casi siempre, una reserva
     * olvidada.
     *
     * @return Collection<int, array{
     *     oferta_id: string,
     *     propiedad_id: string,
     *     estado_id: string,
     *     direccion: string,
     *     comuna: string,
     *     corredor: string|null,
     *     estado: string,
     *     estado_color: string,
     *     dias_en_estado: int,
     *     en_curso_desde: string
     * }>
     */
    public function propiedadesEnCurso(
        string $tenantId,
        TipoOperacion $tipo,
        ?string $corredorId = null,
        ?string $comuna = null,
    ): Collection {
        $consulta = DB::table('ofertas')
            ->join('propiedades', 'propiedades.id', '=', 'ofertas.propiedad_id')
            ->join('estados', 'estados.id', '=', 'ofertas.estado_id')
            ->leftJoin('usuarios', 'usuarios.id', '=', 'ofertas.corredor_id')
            ->where('ofertas.tenant_id', $tenantId)
            ->where('ofertas.tipo_operacion', $tipo->value)
            // Una oferta desactivada (el dueño la retiro) no ocupa el mercado:
            // contarla como "en curso" inflaria el indicador de reservas
            // colgadas con propiedades que nadie esta vendiendo.
            ->where('ofertas.activa', true);

        if ($corredorId !== null) {
            $consulta->where('ofertas.corredor_id', $corredorId);
        }

        if ($comuna !== null) {
            $consulta->where('propiedades.comuna', $comuna);
        }

        return $consulta
            ->select([
                'ofertas.id as oferta_id',
                'propiedades.id as propiedad_id',
                'propiedades.direccion',
                'propiedades.comuna',
                'usuarios.nombre as corredor',
                'estados.nombre as estado',
                'estados.color as estado_color',
                'estados.id as estado_id',
                'ofertas.estado_desde',
            ])
            ->get()
            ->map(function (object $fila): array {
                $desde = \Illuminate\Support\Carbon::parse($fila->estado_desde);

                return [
                    'oferta_id' => (string) $fila->oferta_id,
                    'propiedad_id' => (string) $fila->propiedad_id,
                    'estado_id' => (string) $fila->estado_id,
                    'direccion' => (string) $fila->direccion,
                    'comuna' => (string) $fila->comuna,
                    'corredor' => $fila->corredor,
                    'estado' => (string) $fila->estado,
                    'estado_color' => (string) $fila->estado_color,
                    'dias_en_estado' => max(0, (int) $desde->diffInDays(now())),
                    'en_curso_desde' => $desde->toIso8601String(),
                ];
            })
            // Lo mas antiguo primero: el orden de la lista ES el diagnostico.
            ->sortByDesc('dias_en_estado')
            ->values();
    }

    /**
     * Tramos que tocan el tipo de operacion, con la comuna como filtro opcional.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function tramosDe(string $tenantId, TipoOperacion $tipo, ?string $comuna): Collection
    {
        $consulta = DB::table('cambios_estado')
            ->join('ofertas', 'ofertas.id', '=', 'cambios_estado.oferta_id')
            ->join('propiedades', 'propiedades.id', '=', 'ofertas.propiedad_id')
            ->where('cambios_estado.tenant_id', $tenantId)
            ->where('ofertas.tipo_operacion', $tipo->value);

        if ($comuna !== null) {
            $consulta->where('propiedades.comuna', $comuna);
        }

        return $consulta->select([
            'cambios_estado.estado_nuevo_id',
            'cambios_estado.estado_anterior_id',
            'cambios_estado.desde',
            'cambios_estado.hasta',
            'cambios_estado.duracion_dias',
        ])->get()->map(fn (object $f): array => (array) $f);
    }

    /**
     * @param  Collection<int, int>  $duraciones  ordenadas de menor a mayor.
     */
    private function promedio(Collection $duraciones): ?float
    {
        if ($duraciones->isEmpty()) {
            return null;
        }

        return round($duraciones->avg(), 1);
    }

    /**
     * Percentil por interpolacion entre los dos valores vecinos.
     *
     * @param  Collection<int, int>  $duraciones  ordenadas de menor a mayor.
     */
    private function percentil(Collection $duraciones, float $percentil): ?float
    {
        if ($duraciones->isEmpty()) {
            return null;
        }

        if ($duraciones->count() === 1) {
            return (float) $duraciones->first();
        }

        // Metodo lineal (R-7). Con pocas muestras es el menos enviesoso: con
        // el metodo "cercano", la mediana de [10, 400] seria 10 y ocultaria
        // justo el caso que el producto viene a mostrar.
        $posicion = $percentil * ($duraciones->count() - 1);
        $inferior = (int) floor($posicion);
        $superior = (int) ceil($posicion);

        if ($inferior === $superior) {
            return (float) $duraciones[$inferior];
        }

        $fraccion = $posicion - $inferior;

        return round(
            $duraciones[$inferior] * (1 - $fraccion) + $duraciones[$superior] * $fraccion,
            1
        );
    }

    /**
     * Dias del tramo abierto mas antiguo del grupo.
     *
     * @param  Collection<int, array<string, mixed>>  $enCurso
     */
    private function diasDelMasAntiguo(Collection $enCurso): ?int
    {
        if ($enCurso->isEmpty()) {
            return null;
        }

        $masAntiguo = $enCurso
            ->max(fn (array $t): int => (int) strtotime((string) $t['desde']));

        return max(0, (int) floor((time() - $masAntiguo) / 86400));
    }
}