<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

/**
 * Controlador base.
 *
 * Aporta la comprobacion de autorizacion, que en esta aplicacion se usa en
 * todos los controladores. El patron es siempre el mismo y merece una regla:
 * autorizar PRIMERO, buscar DESPUES.
 *
 * Autorizar despues de buscar el recurso suena inocuo, pero es como se cuelan
 * los errores: el filtro de cartera se puede meter en la consulta (bien) o
 * dejar que la consulta traiga la fila y la policy la rechace (mal, porque
 * entonces la fila ya estuvo en memoria y ya se respondio parte de la peticion).
 * La policy va antes.
 */
abstract class Controller extends BaseController
{
    use AuthorizesRequests;
    use ValidatesRequests;
}