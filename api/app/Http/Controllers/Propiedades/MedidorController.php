<?php

declare(strict_types=1);

namespace App\Http\Controllers\Propiedades;

use App\Enums\TipoOperacion;
use App\Http\Controllers\Controller;
use App\Services\Estados\MedidorDePermanencia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El tablero: permanencia por estado y propiedades en curso.
 *
 * ESTA ES LA PANTALLA QUE JUSTIFICA LA APLICACION
 * -----------------------------------------------
 * El resto del sistema es un CRM. Esto es lo que no se puede comprar hecho: el
 * tiempo que cada propiedad lleva en cada estado, y el promedio del rubro.
 *
 * QUE FILTRA UN CORREDOR Y QUE NO
 * -------------------------------
 * Un corredor ve SUS estados de SUS propiedades, que es lo que le sirve para
 * trabajar. Un administrador ve los mismos numeros de toda la inmobiliaria, y es
 * el unico que puede comparar contra el promedio historico.
 *
 * El filtro de corredor se aplica aqui, y no se deja que el cliente lo pida por
 * parametro: si un corredor pudiera pedir `corredor_id` de otro, veria la
 * permanencia ajena, que es informacion de la competencia dentro de la misma
 * empresa.
 */
class MedidorController extends Controller
{
    public function __construct(private readonly MedidorDePermanencia $medidor) {}

    /**
     * Permanencia por estado, con promedio, mediana y p90.
     */
    public function permanencia(Request $peticion): JsonResponse
    {
        $validados = $peticion->validate([
            'tipo_operacion' => ['required', 'in:venta,arriendo'],
            'comuna' => ['nullable', 'string', 'max:80'],
        ]);

        $usuario = $peticion->user();
        $tenantId = (string) $usuario->tenant_id;

        $tipo = TipoOperacion::from($validados['tipo_operacion']);

        // Para el administrador el filtro por corredor es opcional: si no manda
        // ninguno, ve el total de la inmobiliaria.
        $corredorId = null;

        if (! $usuario->esAdministrador()) {
            $corredorId = (string) $usuario->getKey();
        } elseif ($peticion->filled('corredor_id')) {
            $peticion->validate(['corredor_id' => ['uuid']]);
            $corredorId = (string) $peticion->input('corredor_id');
        }

        $filas = $this->medidor->porEstado(
            tenantId: $tenantId,
            tipo: $tipo,
            comuna: $validados['comuna'] ?? null,
        );

        // Cuando se pide el tablero de un corredor puntual, las filas se
        // recalculan sobre su cartera en vez de tomar el total de la casa.
        if ($corredorId !== null) {
            $filas = $filas->filter(
                fn (array $fila): bool => $this->tienePropiedadesDe(
                    $tenantId,
                    $tipo,
                    $corredorId,
                    $fila['estado_id'],
                    $validados['comuna'] ?? null,
                )
            )->values();
        }

        return response()->json([
            'tipo_operacion' => $tipo->value,
            'comuna' => $validados['comuna'] ?? null,
            'alcance' => $corredorId ?? 'inmobiliaria',
            'datos' => $filas,
        ]);
    }

    /**
     * Propiedades en su estado actual, ordenadas de mas antigua a mas reciente.
     */
    public function enCurso(Request $peticion): JsonResponse
    {
        $validados = $peticion->validate([
            'tipo_operacion' => ['required', 'in:venta,arriendo'],
            'comuna' => ['nullable', 'string', 'max:80'],
            'estado_id' => ['nullable', 'uuid'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $usuario = $peticion->user();

        $corredorId = $usuario->esAdministrador()
            ? ($peticion->input('corredor_id') ?: null)
            : (string) $usuario->getKey();

        $filas = $this->medidor->propiedadesEnCurso(
            tenantId: (string) $usuario->tenant_id,
            tipo: TipoOperacion::from($validados['tipo_operacion']),
            corredorId: $corredorId === null ? null : (string) $corredorId,
            comuna: $validados['comuna'] ?? null,
        );

        // El filtro por estado se hace sobre la coleccion ya construida: son
        // pocas filas (las que estan en curso, no todo el historial) y la
        // consulta base ya trae lo necesario.
        if ($peticion->filled('estado_id')) {
            $filas = $filas->filter(
                fn (array $fila): bool => $fila['estado_id'] === (string) $peticion->input('estado_id')
            )->values();
        }

        $limite = (int) ($validados['limite'] ?? 50);

        return response()->json([
            'tipo_operacion' => $validados['tipo_operacion'],
            'datos' => $filas->take($limite),
            'total' => $filas->count(),
        ]);
    }

    /**
     * ¿Hay propiedades de este corredor en este estado?
     *
     * Consulta de apoyo del tablero. Va contra la base y no contra un filtro en
     * memoria: el tablero se calcula sobre el total de la casa y despues se
     * recorta, para que la cifra de arriba sea la de todos y no se tenga que
     * repetir el agrupado por corredor.
     */
    private function tienePropiedadesDe(
        string $tenantId,
        TipoOperacion $tipo,
        string $corredorId,
        string $estadoId,
        ?string $comuna,
    ): bool {
        $consulta = \Illuminate\Support\Facades\DB::table('ofertas')
            ->join('propiedades', 'propiedades.id', '=', 'ofertas.propiedad_id')
            ->where('ofertas.tenant_id', $tenantId)
            ->where('ofertas.tipo_operacion', $tipo->value)
            ->where('ofertas.estado_id', $estadoId)
            ->where('ofertas.corredor_id', $corredorId)
            ->where('ofertas.activa', true);

        if ($comuna !== null) {
            $consulta->where('propiedades.comuna', $comuna);
        }

        return $consulta->limit(1)->exists();
    }
}