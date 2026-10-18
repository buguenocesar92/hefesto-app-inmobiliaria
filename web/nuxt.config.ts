// Configuracion de la interfaz de Hefesto Inmobiliaria (Nuxt 4 con render en
// el servidor).
//
// Las versiones estan fijadas por rango mayor, no exacto, a proposito: una
// correccion de seguridad dentro de la rama compatible no deberia exigir
// rehacer la imagen. El digesto exacto queda en deploy/web/Dockerfile.

export default defineNuxtConfig({
    // La interfaz se renderiza en el servidor. Motivo concreto: el tablero de
    // permanencia y la lista de clientes no deben quedar en el HTML de una
    // pagina publica, y una pantalla que se dibuja en el servidor no depende de
    // que el JavaScript haya cargado para no filtrar nada.
    ssr: true,

    compatibilityDate: '2026-10-17',

    devtools: { enabled: false },

    runtimeConfig: {
        // URL interna de la API de Laravel. Solo la usa el servidor de Nuxt:
        // nunca viaja al navegador.
        apiBase: process.env.NUXT_API_BASE || 'http://api:8000',

        public: {
            // Cuantas filas trae el tablero de propiedades en curso.
            filasEnCurso: Number(process.env.NUXT_FILAS_EN_CURSO || 50),
        },
    },

    css: ['~/assets/css/inmobiliaria.css'],

    app: {
        head: {
            htmlAttrs: { lang: 'es-CL' },
            title: 'Hefesto Inmobiliaria',
            meta: [
                { charset: 'utf-8' },
                { name: 'viewport', content: 'width=device-width, initial-scale=1' },
                // La aplicacion no se indexa: no es un sitio publico.
                { name: 'robots', content: 'noindex, nofollow' },
                { name: 'referrer', content: 'same-origin' },
            ],
        },
    },

    nitro: {
        // El BFF reenvia la cookie de sesion de Laravel. Por eso la aplicacion
        // de Nuxt NO necesita ningun token propio.
        compressPublicAssets: true,
    },

    routeRules: {
        // Nada que dependa de una sesion se cachea en un proxy. El tablero
        // cambia con cada cambio de estado, y un tablero cacheado es un tablero
        // que muestra una reserva que ya se libero.
        '/**': { headers: { 'cache-control': 'no-store' } },
        '/login': { ssr: true },
    },
})