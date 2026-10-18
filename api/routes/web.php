<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Administracion\EstadoController;
use App\Http\Controllers\Administracion\UsuarioController;
use App\Http\Controllers\Clientes\ClienteController;
use App\Http\Controllers\Clientes\SolicitudController;
use App\Http\Controllers\Operaciones\OperacionController;
use App\Http\Controllers\Propiedades\CambioEstadoController;
use App\Http\Controllers\Propiedades\MedidorController;
use App\Http\Controllers\Propiedades\OfertaController;
use App\Http\Controllers\Propiedades\PropiedadController;
use App\Http\Controllers\Visitas\VisitaController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| Rutas de la API de Hefesto Inmobiliaria
|-------------------------------------------------------------------------------
|
| Todas cuelgan del grupo `web` de Laravel (ver bootstrap/app.php) y del prefijo
| `/api/v1`, que se aplica aqui y no en el esqueleto: queda a la vista que TODA
| la superficie de la API esta versionada en una sola linea.
|
| Estas rutas las consume el BFF de Nuxt (web/server/api), que reenvia las
| cookies del navegador. Ver docs/01 y docs/09.
|
| EL CONTROL FINO NO ESTA ACA. Las rutas declaran que recurso se toca; quien
| puede tocarlo se decide en App\Policies. Ver docs/03.
|
*/
Route::prefix('api/v1')->name('api.v1.')->group(function (): void {

    /*
    |--------------------------------------------------------------------------
    | Autenticacion (OIDC contra Authentik)
    |--------------------------------------------------------------------------
    */
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::get('login', [AuthController::class, 'login'])->name('login');
        Route::get('callback', [AuthController::class, 'callback'])->name('callback');

        Route::middleware('auth')->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::get('usuario', [AuthController::class, 'usuario'])->name('usuario');
        });
    });

    Route::middleware('auth')->group(function (): void {

        /*
        |-----------------------------------------------------------------------
        | EL TABLERO: lo que el producto viene a entregar
        |-----------------------------------------------------------------------
        | Permanencia por estado y propiedades que llevan mucho tiempo en el
        | estado en que estan. Es la pantalla que justifica la aplicacion.
        */
        Route::get('tablero/permanencia', [MedidorController::class, 'permanencia'])
            ->name('tablero.permanencia');

        Route::get('tablero/en-curso', [MedidorController::class, 'enCurso'])
            ->name('tablero.en-curso');

        /*
        |-----------------------------------------------------------------------
        | Propiedades y ofertas
        |-----------------------------------------------------------------------
        | La oferta va aparte de la propiedad porque una propiedad puede estar en
        | venta y en arriendo a la vez, cada una con su propio estado. Ver docs/02.
        */
        Route::apiResource('propiedades', PropiedadController::class)
            ->only(['index', 'store', 'show', 'update'])
            ->parameters(['propiedades' => 'propiedad'])
            ->names('propiedades');

        Route::apiResource('ofertas', OfertaController::class)
            ->only(['index', 'store', 'show', 'update'])
            ->parameters(['ofertas' => 'oferta'])
            ->names('ofertas');

        /*
        | Cambiar el estado de una oferta. Ruta separada de `update` porque no es
        | editar: es el movimiento que escribe el historial, y que dispara los
        | calculos de permanencia.
        */
        Route::post('ofertas/{oferta}/estado', [CambioEstadoController::class, 'store'])
            ->name('ofertas.estado.store');

        // El historial, y el tramo abierto.
        Route::get('ofertas/{oferta}/historial', [CambioEstadoController::class, 'index'])
            ->name('ofertas.historial');

        /*
        |-----------------------------------------------------------------------
        | Clientes, solicitudes y la union con propiedades
        |-----------------------------------------------------------------------
        */
        Route::apiResource('clientes', ClienteController::class)
            ->only(['index', 'store', 'show', 'update'])
            ->parameters(['clientes' => 'cliente'])
            ->names('clientes');

        Route::apiResource('clientes.solicitudes', SolicitudController::class)
            ->only(['index', 'store', 'show', 'update'])
            ->parameters(['clientes' => 'cliente', 'solicitudes' => 'solicitud'])
            ->names('clientes.solicitudes');

        // Proponer una propiedad para una solicitud. Es la escritura de la
        // relacion de varios a varios.
        Route::post('solicitudes/{solicitud}/propiedades', [SolicitudController::class, 'proponerPropiedad'])
            ->name('solicitudes.propiedades.store');

        /*
        |-----------------------------------------------------------------------
        | Visitas
        |-----------------------------------------------------------------------
        */
        Route::apiResource('visitas', VisitaController::class)
            ->only(['index', 'store', 'show', 'update'])
            ->parameters(['visitas' => 'visita'])
            ->names('visitas');

        /*
        |-----------------------------------------------------------------------
        | Operaciones: reservas y arriendos
        |-----------------------------------------------------------------------
        | Crear reservas y arriendos lo puede hacer un corredor.
        |
        | CONFIRMAR y RENOVAR no: son los dos actos que mueven dinero y quitan
        | la propiedad del mercado. Ver OperacionPolicy::confirmar().
        */
        Route::apiResource('operaciones', OperacionController::class)
            ->only(['index', 'store', 'show'])
            ->parameters(['operaciones' => 'operacion'])
            ->names('operaciones');

        Route::post('operaciones/{operacion}/confirmar', [OperacionController::class, 'confirmar'])
            ->name('operaciones.confirmar');

        Route::post('operaciones/{operacion}/renovar', [OperacionController::class, 'renovar'])
            ->name('operaciones.renovar');

        /*
        |-----------------------------------------------------------------------
        | Administracion
        |-----------------------------------------------------------------------
        | Con el middleware `admin`: configuracion de estados y corredores.
        */
        Route::prefix('admin')->name('admin.')->middleware('admin')->group(function (): void {
            Route::apiResource('estados', EstadoController::class)
                ->only(['index', 'store', 'show', 'update'])
                ->parameters(['estados' => 'estado'])
                ->names('estados');

            Route::apiResource('usuarios', UsuarioController::class)
                ->only(['index', 'store', 'show', 'update'])
                ->parameters(['usuarios' => 'usuario'])
                ->names('usuarios');
        });
    });
});