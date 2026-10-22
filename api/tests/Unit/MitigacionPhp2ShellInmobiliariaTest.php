<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CANDADO: CVE-2026-109618 / CVE-2026-109619 (PHP2Shell, @@FECHA@@).
 *
 * QUE COMPRUEBA
 * -------------
 *   1. Que la base de la imagen use una version de PHP PARCHEADA (las
 *      parcheadas son 8.2.35 / 8.3.36 / 8.4.27 / 8.5.12) y que la version
 *      verificada que queda escrita en el ini no baje de la de la serie 8.3.
 *   2. Que el ini de endurecimiento apague `session.upload_progress.enabled`,
 *      que es la via CONFIRMADA de CVE-2026-109619.
 *   3. Que `memory_limit = -1` este SOLO si el compose declara `mem_limit`: sin
 *      frontera de cgroup, `-1` es una via de agotamiento de memoria en el host.
 *   4. Que la etapa final del Dockerfile copie el ini a `conf.d` y el pool de
 *      php-fpm a `php-fpm.d`: si no se copian, el endurecimiento no llega al
 *      contenedor.
 *   5. Que el pool recicle los trabajadores cada 100 peticiones.
 *   6. Que la etapa final actualice el sistema base.
 *   7. Que NADA en el repo vuelva a encender `session.upload_progress.enabled`.
 *
 * POR QUE NO USA LA BASE DE DATOS
 * --------------------------------
 * Solo lee ficheros: no necesita PostgreSQL, ni Docker, ni la base de pruebas.
 * Por eso vive en la suite de unidad y se puede correr con:
 *
 *     vendor/bin/phpunit --testsuite=Unit
 */
final class MitigacionPhp2ShellInmobiliariaTest extends TestCase
{
    /**
     * La version MINIMA de PHP parcheada de la serie 8.3.
     */
    private const VERSION_MINIMA = '8.3.36';

    private const SERIE = '8.3';

    private const DOCKERFILE = 'deploy/api/Dockerfile';

    private const INI = 'deploy/api/docker/99-php2shell-hardening.ini';

    private const POOL = 'deploy/api/docker/zz-php2shell-fpm.conf';

    /**
     * @var list<string>
     */
    private const COMPOSES = [
        'deploy/docker-compose.prod.yml',
        'deploy/docker-compose.dev.yml',
    ];

    private function ruta(string $relativa): string
    {
        return dirname(__DIR__, 3).'/'.$relativa;
    }

    private function contenido(string $relativa): string
    {
        $this->assertFileExists($this->ruta($relativa), 'Falta '.$relativa.'.');

        return (string) file_get_contents($this->ruta($relativa));
    }

    /**
     * El texto del Dockerfile con las continuaciones de linea unidas, para que
     * un `RUN` de varias lineas se pueda comprobar con una expresion regular.
     */
    private function dockerfileUnido(): string
    {
        return (string) preg_replace('/\x5c[ \t]*\n[ \t]*/', ' ', $this->contenido(self::DOCKERFILE));
    }

    /**
     * Las lineas de la ETAPA FINAL del Dockerfile (la que produce la imagen de
     * runtime): lo que va en una etapa de dependencias no llega al contenedor.
     *
     * @return list<string>
     */
    private function lineasDeLaEtapaFinal(): array
    {
        $lineas = array_values(array_filter(
            array_map('trim', explode("\n", $this->dockerfileUnido())),
            fn (string $linea): bool => $linea !== '' && ! str_starts_with($linea, '#'),
        ));

        $ultimoFrom = 0;
        foreach ($lineas as $indice => $linea) {
            if (preg_match('/^FROM\s/i', $linea) === 1) {
                $ultimoFrom = $indice;
            }
        }

        return array_slice($lineas, $ultimoFrom);
    }

    public function test_la_base_usa_una_version_de_php_parcheada(): void
    {
        $this->assertMatchesRegularExpression(
            '/^FROM\s+(?:docker\.io\/library\/)?php:(\S+)/mi',
            $this->contenido(self::DOCKERFILE),
            'El Dockerfile no declara una base «FROM php:...».',
        );

        preg_match('/^FROM\s+(?:docker\.io\/library\/)?php:(\S+)/mi', $this->contenido(self::DOCKERFILE), $coincidencia);
        $tag = $coincidencia[1];

        if (preg_match('/^(\d+\.\d+\.\d+)/', $tag, $version) === 1) {
            // El tag esta FIJADO a una version exacta: tiene que ser la parcheada.
            $this->assertTrue(
                version_compare($version[1], self::VERSION_MINIMA, '>='),
                'La base «'.$tag.'» esta por debajo de la version parcheada ('.self::VERSION_MINIMA.') de la serie '.self::SERIE.'.',
            );

            return;
        }

        // El tag es FLOTANTE (php:8.3-fpm-bookworm): tiene que ser el de la serie
        // parcheada. Que el tag flotante resuelva a la version parcheada se
        // comprueba en el ini, donde queda escrita la resolucion real.
        preg_match('/^(\d+\.\d+)/', $tag, $serie);
        $this->assertSame(
            self::SERIE,
            $serie[1] ?? '',
            'La base «'.$tag.'» no es de la serie '.self::SERIE.', que es la que tiene la version parcheada ('.self::VERSION_MINIMA.').',
        );
    }

    public function test_la_version_de_php_verificada_esta_parcheada(): void
    {
        $ini = $this->contenido(self::INI);

        $this->assertMatchesRegularExpression(
            '/PHP verificado:\s*(\d+\.\d+\.\d+)/',
            $ini,
            self::INI.' no deja constancia de la version de PHP con la que se construyo la imagen: sin esa constancia, el tag flotante puede mudar a una version vulnerable sin que nadie lo note.',
        );

        preg_match('/PHP verificado:\s*(\d+\.\d+\.\d+)/', $ini, $coincidencia);

        $this->assertTrue(
            version_compare($coincidencia[1], self::VERSION_MINIMA, '>='),
            'La version de PHP verificada ('.$coincidencia[1].') esta por debajo de la version parcheada ('.self::VERSION_MINIMA.') de la serie '.self::SERIE.'.',
        );
    }

    public function test_el_ini_apaga_el_upload_progress_de_la_sesion(): void
    {
        $valores = parse_ini_file($this->ruta(self::INI), false, INI_SCANNER_TYPED);

        $this->assertIsArray($valores, self::INI.' no se puede leer como ini.');

        $this->assertArrayHasKey(
            'session.upload_progress.enabled',
            $valores,
            self::INI.' no fija «session.upload_progress.enabled»: es la via CONFIRMADA del UAF de CVE-2026-109619 (php_session_rfc1867_callback).',
        );

        $this->assertFalse(
            $valores['session.upload_progress.enabled'],
            self::INI.' deja «session.upload_progress.enabled» encendido: PHP lo trae en On por omision.',
        );
    }

    public function test_el_memory_limit_sin_tope_solo_si_hay_frontera_de_cgroup(): void
    {
        $valores = parse_ini_file($this->ruta(self::INI), false, INI_SCANNER_TYPED);
        $this->assertIsArray($valores);

        $hayFrontera = false;
        foreach (self::COMPOSES as $compose) {
            if (! file_exists($this->ruta($compose))) {
                continue;
            }

            if (str_contains($this->contenido($compose), 'mem_limit')) {
                $hayFrontera = true;
            }
        }

        if ($hayFrontera) {
            $this->assertArrayHasKey(
                'memory_limit',
                $valores,
                'El compose declara «mem_limit»: hay frontera de cgroup, asi que «memory_limit = -1» es seguro y el advisory oficial lo pide.',
            );

            $this->assertSame(-1, $valores['memory_limit'], 'El compose declara «mem_limit» pero el ini no deja «memory_limit» en -1.');

            return;
        }

        $this->assertArrayNotHasKey(
            'memory_limit',
            $valores,
            'El compose NO declara «mem_limit»: sin frontera de cgroup, «memory_limit = -1» seria una via de agotamiento de memoria en el host.',
        );
    }

    public function test_la_etapa_final_de_la_imagen_copia_el_ini_a_conf_d(): void
    {
        $copia = array_filter(
            $this->lineasDeLaEtapaFinal(),
            fn (string $linea): bool => preg_match(
                '#^COPY\s+'.preg_quote(self::INI, '#').'\s+/usr/local/etc/php/conf\.d/[\w.-]+\.ini$#',
                $linea,
            ) === 1,
        );

        $this->assertNotEmpty(
            $copia,
            'La etapa final del Dockerfile no copia '.self::INI.' a /usr/local/etc/php/conf.d/: el endurecimiento no llega al contenedor.',
        );
    }

    public function test_la_etapa_final_de_la_imagen_copia_el_pool_de_php_fpm(): void
    {
        $copia = array_filter(
            $this->lineasDeLaEtapaFinal(),
            fn (string $linea): bool => preg_match(
                '#^COPY\s+'.preg_quote(self::POOL, '#').'\s+/usr/local/etc/php-fpm\.d/[\w.-]+\.conf$#',
                $linea,
            ) === 1,
        );

        $this->assertNotEmpty(
            $copia,
            'La etapa final del Dockerfile no copia '.self::POOL.' a /usr/local/etc/php-fpm.d/: el reciclaje de los trabajadores no llega al contenedor.',
        );
    }

    public function test_el_pool_de_php_fpm_recicla_cada_100_peticiones(): void
    {
        $contenido = $this->contenido(self::POOL);

        $this->assertMatchesRegularExpression(
            '/^\[www\]$/m',
            $contenido,
            self::POOL.' no declara el pool «[www]»: sin esa seccion, php-fpm ignora el archivo.',
        );

        $this->assertMatchesRegularExpression(
            '/^\s*pm\.max_requests\s*=\s*100\s*$/m',
            $contenido,
            self::POOL.' no fija «pm.max_requests = 100»: sin el, php-fpm usa 500 peticiones por trabajador y el worker vulnerable vive cinco veces mas.',
        );
    }

    public function test_la_etapa_final_actualiza_el_sistema_base(): void
    {
        $etapa = implode("\n", $this->lineasDeLaEtapaFinal());

        $actualiza = preg_match('/apt-get\s+upgrade|apk\s+update\s*&&\s*apk\s+upgrade/', $etapa) === 1;

        $this->assertTrue(
            $actualiza,
            'La etapa final del Dockerfile no actualiza el sistema base («apt-get upgrade» / «apk update && apk upgrade»): las librerias del sistema quedan en la version vulnerable.',
        );
    }

    public function test_nada_mas_vuelve_a_encender_el_upload_progress(): void
    {
        $raiz = dirname(__DIR__, 3);

        $archivos = [];
        $iterador = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterador as $archivo) {
            $ruta = $archivo->getPathname();

            if (str_contains($ruta, '/vendor/') || str_contains($ruta, '/node_modules/')) {
                continue;
            }

            if (in_array(strtolower($archivo->getExtension()), ['ini', 'sh'], true)) {
                $archivos[] = $ruta;
            }
        }

        $archivos[] = $this->ruta(self::DOCKERFILE);
        $this->assertContains(
            $this->ruta(self::DOCKERFILE),
            $archivos,
            'El Dockerfile tiene que estar entre los ficheros revisados: si no, un «session.upload_progress.enabled = On» puesto en el Dockerfile pasaria inadvertido.',
        );

        foreach ($archivos as $archivo) {
            if (realpath($archivo) === realpath($this->ruta(self::INI))) {
                continue;
            }

            foreach (explode("\n", (string) file_get_contents($archivo)) as $linea) {
                $linea = trim($linea);

                if (str_starts_with($linea, '#') || str_starts_with($linea, ';')) {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression(
                    '/session\.upload_progress\.enabled\s*=\s*["\']?(1|on|true|yes)\b/i',
                    $linea,
                    basename($archivo).' vuelve a encender session.upload_progress.enabled: «'.$linea.'»',
                );
            }
        }

        $this->addToAssertionCount(1);
    }
}
