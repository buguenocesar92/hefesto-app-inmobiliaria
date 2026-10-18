<?php

declare(strict_types=1);

use App\Http\Middleware\ResolverTenant;
use App\Http\Middleware\SoloAdministrador;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Punto de arranque de la API de Hefesto Inmobiliaria.
 *
 * Nota sobre el esqueleto: este archivo reemplaza al que genera
 * `composer create-project laravel/laravel`. El resto del esqueleto (artisan,
 * public/index.php, storage) lo genera Composer. Ver docs/04.
 *
 * Todas las rutas cuelgan del grupo `web` de Laravel porque la interfaz (Nuxt)
 * se autentica con cookie de sesion: la cookie viaja en el mismo origen y el
 * token CSRF se reenvia como cabecera X-XSRF-TOKEN. Ver docs/01.
 */
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/api/v1/salud',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // ResolverTenant envuelve la peticion autenticada en una transaccion con
        // el tenant ya fijado, para que la RLS sepa de quien son las filas.
        // Corre ANTES que `auth`, por eso resuelve el usuario por su cuenta.
        $middleware->web(append: [
            ResolverTenant::class,
        ]);

        $middleware->alias([
            // Configuracion de estados, corredores y bitacora. Solo el
            // administrador de la inmobiliaria.
            'admin' => SoloAdministrador::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Un rechazo de autorizacion dentro de la API responde 404, no 403: un
        // 403 confirmaria que el recurso existe. En una inmobiliaria esto
        // importa mas de lo habitual, porque una propiedad y un cliente de otra
        // cartera se ven exactamente igual que uno propio.
        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/v1/*')) {
                return response()->json([
                    'mensaje' => 'No encontrado.',
                ], 404);
            }

            return null;
        });

        // Los errores de validacion devuelven 422 con el detalle por campo, que
        // es lo que la interfaz necesita para marcar el formulario.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/v1/*')) {
                return response()->json([
                    'mensaje' => 'Revisa los datos ingresados.',
                    'errores' => $e->errors(),
                ], 422);
            }

            return null;
        });
    })
    ->create();