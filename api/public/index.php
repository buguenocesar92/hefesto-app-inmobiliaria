<?php

declare(strict_types=1);

use Illuminate\Http\Request;

/*
|------------------------------------------------------------------------------
| Punto de entrada HTTP
|------------------------------------------------------------------------------
|
| Este archivo reemplaza al que genera `composer create-project laravel/laravel`,
| igual que bootstrap/app.php. Ver docs/04.
|
| No hay logica de negocio aqui. Toda la superficie de la API esta en
| routes/web.php, bajo el prefijo /api/v1.
|
*/

define('LARAVEL_START', microtime(true));

require __DIR__ . '/../vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require_once __DIR__ . '/../bootstrap/app.php';

$app->handleRequest(Request::capture());