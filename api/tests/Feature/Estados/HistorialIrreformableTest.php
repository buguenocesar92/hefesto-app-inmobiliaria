<?php

declare(strict_types=1);

namespace Tests\Feature\Estados;

use App\Enums\EstadoComercial;
use App\Models\CambioEstado;
use App\Models\Estado;
use App\Models\Oferta;
use App\Services\Estados\RegistradorDeCambioEstado;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El historial no se reescribe.
 *
 * POR QUE ESTO NECESITA SU PROPRIO ARCHIVO
 * ---------------------------------------
 * Todo el producto se apoya en que `cambios_estado` diga la verdad. Si alguien
 * puede corregir una duracion "porque se anoto mal la fecha", la linea de tiempo
 * deja de ser un registro y pasa a ser un borrador: el promedio de permanencia
 * se vuelve un numero que uno puede convencer.
 *
 * Y el escenario realista no es un atacante: es un corredor que entra un dia
 * tarde a cargar una reserva y quiere que el numero salga bien. El disparador de
 * PostgreSQL existe para que esa intencion, que es inocua en apariencia, no
 * tenga con que pasar por el filtro de la buena fe.
 *
 * LO QUE SE COMPRUEBA
 *   1. Un tramo cerrado no puede cambiar su `desde`.
 *   2. Un tramo no puede cerrarse sin su duracion.
 *   3. Un tramo abierto SI puede cerrarse (es la operacion normal).
 */
class HistorialIrreformableTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_se_puede_cambiar_el_inicio_de_un_tramo_cerrado(): void
    {
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $oferta = $this->crearOfertaDeVenta($inmobiliaria);

        $cerrado = $this->cerrarUnTramo($inmobiliaria, $oferta);

        $this->assertFalse($cerrado->estaAbierto(), 'El tramo deberia estar cerrado para esta prueba.');

        // Intento de "corregir" la fecha de inicio para acortar la duracion.
        $this->expectException(QueryException::class);

        $this->dentroDe($inmobiliaria, function () use ($cerrado): void {
            $cerrado->forceFill([
                'desde' => Carbon::now()->subDays(2),
            ])->save();
        });
    }

    public function test_no_se_puede_cerrar_un_tramo_sin_medirlo(): void
    {
        // Cerrado sin duracion seria un tramo invisible para el informe: la
        // propiedad habria estado en ese estado, y el tablero no lo sabria.
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $oferta = $this->crearOfertaDeVenta($inmobiliaria);

        $abierto = $this->dentroDe($inmobiliaria, fn (): CambioEstado => CambioEstado::query()
            ->where('oferta_id', $oferta->id)
            ->whereNull('hasta')
            ->firstOrFail());

        $this->expectException(QueryException::class);

        $this->dentroDe($inmobiliaria, function () use ($abierto): void {
            $abierto->forceFill([
                'hasta' => Carbon::now(),
                // `duracion_dias` se queda en null a proposito.
            ])->save();
        });
    }

    public function test_un_tramo_abierto_si_se_puede_cerrar(): void
    {
        // Lo contrario seria imposible: si no se pudiera cerrar nunca, la
        // duracion no se mediria y el producto no tendria nada que vender.
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $oferta = $this->crearOfertaDeVenta($inmobiliaria);

        $cerrado = $this->cerrarUnTramo($inmobiliaria, $oferta);

        $cerrado->refresh();

        $this->assertFalse($cerrado->estaAbierto());
        $this->assertNotNull($cerrado->duracion_dias);
    }

    public function test_el_servicio_si_puede_cerrar_un_tramo_sin_duracion_previa(): void
    {
        // La comprobacion anterior parece contradecir esta, y no lo es: el
        // servicio cierra escribiendo `desde`, `hasta` Y `duracion_dias` en la
        // misma sentencia. Lo que se prohibe es cerrar SIN la duracion.
        $inmobiliaria = $this->crearInmobiliaria('Inmobiliaria de prueba');
        $oferta = $this->crearOfertaDeVenta($inmobiliaria);

        $reservada = $this->dentroDe($inmobiliaria, fn (): Estado => Estado::query()
            ->where('clave_sistema', EstadoComercial::Reservada->value)
            ->firstOrFail());

        $this->dentroDe($inmobiliaria, function () use ($oferta, $reservada): void {
            app(RegistradorDeCambioEstado::class)->registrar(
                oferta: $oferta->refresh(),
                estadoNuevo: $reservada,
                motivo: 'Reserva tomada',
                usuarioId: null,
            );
        });

        $cerrados = $this->dentroDe($inmobiliaria, fn (): int => CambioEstado::query()
            ->where('oferta_id', $oferta->id)
            ->whereNotNull('hasta')
            ->whereNotNull('duracion_dias')
            ->count());

        $this->assertSame(1, $cerrados);
    }

    /**
     * Abre y cierra un tramo con el servicio, y devuelve el tramo cerrado.
     */
    private function cerrarUnTramo(\App\Models\Inmobiliaria $inmobiliaria, Oferta $oferta): CambioEstado
    {
        $reservada = $this->dentroDe($inmobiliaria, fn (): Estado => Estado::query()
            ->where('clave_sistema', EstadoComercial::Reservada->value)
            ->firstOrFail());

        $disponible = $this->dentroDe($inmobiliaria, fn (): Estado => Estado::query()
            ->where('clave_sistema', EstadoComercial::Disponible->value)
            ->firstOrFail());

        $this->dentroDe($inmobiliaria, function () use ($oferta, $reservada, $disponible): void {
            $registrador = app(RegistradorDeCambioEstado::class);

            $registrador->registrar($oferta->refresh(), $reservada, 'Reserva tomada', null);
            $registrador->registrar($oferta->refresh(), $disponible, 'Reserva liberada', null);
        });

        return $this->dentroDe($inmobiliaria, fn (): CambioEstado => CambioEstado::query()
            ->where('oferta_id', $oferta->id)
            ->whereNotNull('hasta')
            ->orderBy('desde')
            ->firstOrFail());
    }
}