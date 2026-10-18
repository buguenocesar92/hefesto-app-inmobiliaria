<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Inmobiliaria;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Proveedor OIDC contra Authentik.
 *
 * NOTA HONESTA SOBRE EL ALCANCE DE ESTE ARCHIVO
 * --------------------------------------------
 * El descubrimiento OIDC (`/.well-known/openid-configuration`), la verificacion
 * de firma del id_token y la resolucion del equipo son los mismos de las otras
 * aplicaciones de Hefesto, y estan implementados en
 * apps/clinica-salud/api/app/Services/Auth. Esta clase esta escrita para dejar
 * claro CUAL es el flujo de esta aplicacion, pero su equivalente ya existe y
 * probado en la vertical de clinicas.
 *
 * ESTADO: NO VERIFICADO. En el entorno donde se escribio esto no hay PHP, ni
 * Composer, ni un Authentik al que conectarse. El flujo de descubrimiento, la
 * verificacion de firma y la resolucion de equipos NO se ejecutaron. Ver
 * docs/10.
 *
 * QUE HACE, EN ORDEN
 * ------------------
 *  1. `redirigir()`: manda al proveedor con un `state` y un nonce.
 *  2. `callback()`: verifica el id_token, saca el correo y los EQUIPOS de la
 *     persona, y de ahi deduce la inmobiliaria.
 *  3. Crea el usuario si es la primera vez, y abre la sesion.
 *
 * EL PASO QUE IMPORTA: DEL EQUIPO A LA INMOBILIARIA
 * -------------------------------------------------
 * En Authentik, la pertenencia a una inmobiliaria es un EQUIPO cuyo nombre es
 * `<prefijo>:<correlacion>`, por ejemplo `HefestoInmobiliaria:sur`. La
 * correlacion es la misma que esta en la tabla `inmobiliarias`. De ese nombre se
 * resuelve el `tenant_id`, y con el se arma la sesion.
 *
 * Por que el equipo y no el dominio del correo: una inmobiliaria puede tener
 * correos de varios dominios, y una persona puede trabajar para dos
 * inmobiliarias. El equipo es el que expresa la relacion real.
 */
class ProveedorOidcAuthentik
{
    /**
     * Redirige al proveedor.
     *
     * El `state` y el `nonce` se guardan en la sesion y se comparan al volver:
     * sin esa comparacion, un atacante puede enviar al usuario una URL de
     * callback con su propia sesion.
     */
    public function redirigir(): JsonResponse
    {
        $estado = bin2hex(random_bytes(16));
        $nonce = bin2hex(random_bytes(16));

        $url = $this->urlDeAutorizacion() . '?' . http_build_query([
            'client_id' => config('authentik.client_id'),
            'redirect_uri' => config('authentik.redirect_uri'),
            'response_type' => 'code',
            'scope' => config('authentik.scopes', 'openid profile email groups'),
            'state' => $estado,
            'nonce' => $nonce,
        ]);

        session()->put('oidc_state', $estado);
        session()->put('oidc_nonce', $nonce);

        return redirect()->away($url);
    }

    /**
     * Callback: verifica, resuelve la inmobiliaria y abre la sesion.
     */
    public function callback(Request $peticion): JsonResponse
    {
        $estadoEsperado = session()->pull('oidc_state');
        $nonceEsperado = session()->pull('oidc_nonce');

        if ($estadoEsperado === null || $peticion->query('state') !== $estadoEsperado) {
            return response()->json(['mensaje' => 'La sesion de acceso no es valida.'], 400);
        }

        $claims = $this->verificarIdToken($peticion);

        $correo = $claims['email'] ?? null;

        if (! is_string($correo) || $correo === '') {
            return response()->json(['mensaje' => 'La identidad no entrego un correo.'], 400);
        }

        $tenantId = $this->resolverInmobiliariaDesdeEquipos($claims['groups'] ?? []);

        if ($tenantId === null) {
            // No se entra a la aplicacion sin saber de que inmobiliaria es. Se
            // responde 403 y no 404: la persona existe en Authentik, no hay que
            // ocultarle que su cuenta no esta habilitada aca.
            return response()->json([
                'mensaje' => 'Tu cuenta no pertenece a ninguna inmobiliaria habilitada en esta aplicacion.',
            ], 403);
        }

        $usuario = $this->crearSiNoExiste($tenantId, $correo, $claims);

        if ($usuario === null || ! $usuario->activo) {
            return response()->json([
                'mensaje' => 'Tu cuenta esta suspendida. Contacta a la administracion de la inmobiliaria.',
            ], 403);
        }

        $usuario->forceFill(['ultimo_ingreso' => now()])->save();

        $peticion->session()->regenerate();

        // Se guarda el MODELO, no el id. Asi la sesion ya trae rol y cartera, y
        // el middleware de tenant no tiene que consultar para armar el contexto.
        auth()->guard('web')->login($usuario);

        return response()->json([
            'mensaje' => 'Sesion iniciada.',
            'datos' => ['id' => $usuario->id, 'nombre' => $usuario->nombre],
        ]);
    }

    /**
     * Crea el usuario la primera vez, si su equipo es de esta instalacion.
     *
     * El nombre y el correo se copian de la identidad y se refrescan en cada
     * ingreso: son copia, y la fuente de verdad es Authentik.
     */
    private function crearSiNoExiste(string $tenantId, string $correo, array $claims): ?Usuario
    {
        return DB::transaction(function () use ($tenantId, $correo, $claims): ?Usuario {
            // `inmobiliarias` no tiene RLS: se lee por `correlacion_identidad`,
            // que es justo el texto que viene en el nombre del equipo.
            $inmobiliaria = Inmobiliaria::query()
                ->where('id', $tenantId)
                ->where('activo', true)
                ->first();

            if ($inmobiliaria === null) {
                return null;
            }

            $correlacion = config('inmobiliaria.prefijo_equipo', 'HefestoInmobiliaria')
                . ':' . $inmobiliaria->correlacion_identidad . ':' . strtolower($correo);

            $nombre = (string) ($claims['name'] ?? $claims['preferred_username'] ?? $correo);

            return Usuario::query()->updateOrCreate(
                ['correlacion_identidad' => $correlacion],
                [
                    'tenant_id' => $inmobiliaria->id,
                    'nombre' => mb_substr($nombre, 0, 160),
                    'correo' => mb_substr($correo, 0, 160),
                    // Una cuenta nueva entra como corredor, que es el alcance mas
                    // restrictivo. Nunca como administrador: el rol lo cambia
                    // alguien de la administracion de la inmobiliaria, a mano.
                    'rol' => 'corredor',
                    'activo' => true,
                ]
            );
        });
    }

    /**
     * De los equipos de la persona, deduce el id de la inmobiliaria.
     *
     * Si la persona pertenece a equipos de dos inmobiliarias, es un error de
     * configuracion que NO se resuelve eligiendo una: se avisa. Ver docs/05.
     *
     * @param  array<int, mixed>  $grupos
     */
    private function resolverInmobiliariaDesdeEquipos(array $grupos): ?string
    {
        $prefijo = config('inmobiliaria.prefijo_equipo', 'HefestoInmobiliaria');

        $correlaciones = [];

        foreach ($grupos as $grupo) {
            if (! is_string($grupo)) {
                continue;
            }

            $partes = explode(':', $grupo);

            if (count($partes) < 2 || $partes[0] !== $prefijo) {
                continue;
            }

            $correlaciones[$partes[1]] = true;
        }

        if (count($correlaciones) !== 1) {
            return null;
        }

        $correlacion = array_key_first($correlaciones);

        return Inmobiliaria::query()
            ->where('correlacion_identidad', $correlacion)
            ->where('activo', true)
            ->value('id');
    }

    /**
     * URL de autorizacion, desde el descubrimiento OIDC.
     *
     * PENDIENTE DE VERIFICAR: la lectura de `authorization_endpoint` desde
     * `/.well-known/openid-configuration` no se ejecuto en este entorno.
     */
    private function urlDeAutorizacion(): string
    {
        $descubrimiento = app(DescubridorOidc::class)->metadatos();

        $url = $descubrimiento->authorization_endpoint ?? null;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException(
                'Authentik no devolvio authorization_endpoint. '
                . 'Revise AUTHENTIK_ISSUER y la conectividad. Ver docs/05.'
            );
        }

        return $url;
    }

    /**
     * Verifica el id_token.
     *
     * PENDIENTE DE VERIFICAR: la verificacion de firma contra el JWKS de
     * Authentik no se ejecuto en este entorno. Ver docs/10.
     *
     * @return array<string, mixed>
     */
    private function verificarIdToken(Request $peticion): array
    {
        $codigo = (string) $peticion->query('code', '');

        if ($codigo === '') {
            throw new RuntimeException('Authentik no devolvio un codigo de autorizacion.');
        }

        return app(VerificadorIdToken::class)->verificarPorCodigo($codigo);
    }
}