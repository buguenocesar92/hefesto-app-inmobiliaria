<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Verificacion del id_token y canje del codigo de autorizacion.
 *
 * ESTADO: NO VERIFICADO
 * ---------------------
 * En el entorno donde se escribio esto no hay PHP, ni Composer, ni un Authentik
 * real, asi que el canje del codigo y la verificacion de firma NO se ejecutaron
 * contra ningun servidor. Ver docs/10.
 *
 * QUE HACE
 * --------
 *  1. Canjea el `code` por tokens en el `token_endpoint`.
 *  2. Verifica la firma del `id_token` contra el JWKS publicado por Authentik.
 *  3. Comprueba `iss`, `aud`, `exp` y `nonce`.
 *
 * POR QUE SE VERIFICA LA FIRMA Y NO SOLO EL CONTENIDO
 * ---------------------------------------------------
 * Un id_token verificado solo por su contenido es una cadena de texto que
 * cualquiera puede escribir. Sin verificar la firma contra la clave del emisor,
 * "esta persona pertenece al equipo X" es una afirmacion que no sostiene nada.
 */
class VerificadorIdToken
{
    /**
     * Canjea el codigo y devuelve los claims verificados del id_token.
     *
     * @return array<string, mixed>
     */
    public function verificarPorCodigo(string $codigo): array
    {
        $descubrimiento = app(DescubridorOidc::class)->metadatos();

        $endpoint = $descubrimiento->token_endpoint ?? null;

        if (! is_string($endpoint) || $endpoint === '') {
            throw new RuntimeException(
                'Authentik no devolvio token_endpoint en el descubrimiento OIDC. Ver docs/05.'
            );
        }

        $respuesta = Http::asForm()
            ->timeout(10)
            ->post($endpoint, [
                'grant_type' => 'authorization_code',
                'code' => $codigo,
                'redirect_uri' => config('authentik.redirect_uri'),
                'client_id' => config('authentik.client_id'),
                'client_secret' => config('authentik.client_secret'),
            ]);

        if (! $respuesta->successful()) {
            throw new RuntimeException(
                'Authentik rechazo el canje del codigo de autorizacion. Estado: '
                . $respuesta->status() . '. Ver docs/05.'
            );
        }

        $cuerpo = $respuesta->json();
        $idToken = is_array($cuerpo) ? ($cuerpo['id_token'] ?? null) : null;

        if (! is_string($idToken) || $idToken === '') {
            throw new RuntimeException(
                'La respuesta de Authentik no incluyo un id_token. Ver docs/05.'
            );
        }

        $claims = $this->verificarFirma($idToken);

        $this->comprobarClaims($claims);

        return $claims;
    }

    /**
     * Verifica la firma del id_token y devuelve sus claims.
     *
     * PENDIENTE: la firma se verifica con la biblioteca JWT contra el JWKS
     * cacheado. La obtencion del JWKS y el algoritmo se leen de
     * `AUTHENTIK_ALGORITMO`.
     *
     * @return array<string, mixed>
     */
    private function verificarFirma(string $idToken): array
    {
        // La decodificacion sin verificar NUNCA se usa para tomar una decision.
        // Este es el unico lugar donde el id_token se vuelve confiable, y lo
        // que se hace todavia es comprobar que se pueda verificar.
        $decodificado = $this->decodificarParaVerificar($idToken);

        if (! is_array($decodificado) || ! isset($decodificado['alg'])) {
            throw new RuntimeException(
                'El id_token no tiene la forma esperada. Ver docs/05 y docs/07.'
            );
        }

        // `alg: none` es el ataque clasico: un token sin firma que dice ser
        // valido. Se rechaza antes de verificar nada.
        if (strtolower((string) $decodificado['alg']) === 'none') {
            throw new RuntimeException(
                'El id_token declara el algoritmo "none". Se rechaza. Ver docs/07.'
            );
        }

        $esperado = strtoupper((string) config('authentik.algoritmo', 'RS256'));

        if (strtoupper((string) $decodificado['alg']) !== $esperado) {
            throw new RuntimeException(
                'El id_token fue firmado con ' . $decodificado['alg'] . ' y se esperaba ' . $esperado . '.'
            );
        }

        $jwks = $this->jwks();

        $firmado = $this->firmarConJwks($idToken, $jwks);

        if ($firmado !== true) {
            throw new RuntimeException(
                'La firma del id_token no es valida. Se rechaza el ingreso. Ver docs/07.'
            );
        }

        return $firmado;
    }

    /**
     * Descodifica el token SOLO para leer la cabecera y comprobar la forma.
     *
     * Lo que vuelve aca no es confiable: por eso se devuelve en un arreglo
     * marcado como tal y no se usa para decidir nada.
     *
     * @return array<string, mixed>|null
     */
    private function decodificarParaVerificar(string $idToken): ?array
    {
        $partes = explode('.', $idToken);

        if (count($partes) !== 3) {
            return null;
        }

        $cabeza = json_decode(
            (string) base64_decode(strtr($partes[0], '-_', '+/'), true),
            true
        );

        return is_array($cabeza) ? $cabeza : null;
    }

    /**
     * El JWKS del proveedor, desde la cache.
     *
     * @return array<int, mixed>
     */
    private function jwks(): array
    {
        $clave = 'oidc:jwks:' . config('authentik.issuer');

        $cacheado = Cache::get($clave);

        if (is_array($cacheado)) {
            return $cacheado;
        }

        $descubrimiento = app(DescubridorOidc::class)->metadatos();

        $url = $descubrimiento->jwks_uri ?? null;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException('Authentik no devolvio jwks_uri. Ver docs/05.');
        }

        $respuesta = Http::timeout(10)->accept('application/json')->get($url);

        if (! $respuesta->successful()) {
            throw new RuntimeException(
                'No se pudo leer el JWKS de Authentik. Estado: ' . $respuesta->status() . '.'
            );
        }

        $claves = $respuesta->json();

        if (! is_array($claves)) {
            throw new RuntimeException('El JWKS de Authentik no es un arreglo JSON valido.');
        }

        Cache::put($clave, $claves, (int) config('authentik.cache_jwks', 86400));

        return $claves;
    }

    /**
     * Verifica la firma con las claves del JWKS.
     *
     * @param  array<int, mixed>  $jwks
     * @return array<string, mixed>
     */
    private function firmarConJwks(string $idToken, array $jwks): array
    {
        // PENDIENTE: delega en la biblioteca JWT con el JWKS cacheado.
        //
        // Lo que NO se hace aca es devolver los claims "confiables" sin
        // comprobar la firma: por eso el metodo se llama `firmarConJwks` y
        // devuelve claims solo si la comprobacion dio bien.
        throw new RuntimeException(
            'La verificacion criptografica del id_token debe implementarse con la biblioteca '
            . 'JWT antes de usar esto en produccion. Ver docs/10: ESTADO NO VERIFICADO.'
        );
    }

    /**
     * Comprueba `iss`, `aud`, `exp` y `nonce` una vez verificada la firma.
     *
     * @param  array<string, mixed>  $claims
     */
    private function comprobarClaims(array $claims): void
    {
        $emisor = rtrim((string) config('authentik.issuer'), '/');

        if (($claims['iss'] ?? null) !== $emisor) {
            throw new RuntimeException('El id_token no viene del emisor configurado.');
        }

        $audiencia = $claims['aud'] ?? null;
        $esperada = (string) config('authentik.client_id');

        $aud = is_array($audiencia) ? $audiencia : [$audiencia];

        if (! in_array($esperada, $aud, true)) {
            throw new RuntimeException(
                'El id_token no fue emitido para este cliente OIDC.'
            );
        }

        $margen = (int) config('authentik.leeway', 60);

        if (isset($claims['exp']) && (int) $claims['exp'] + $margen < time()) {
            throw new RuntimeException('El id_token esta vencido.');
        }

        $nonce = session()->get('oidc_nonce');

        if ($nonce !== null && ($claims['nonce'] ?? null) !== $nonce) {
            throw new RuntimeException('El nonce del id_token no coincide con el de la peticion.');
        }
    }
}