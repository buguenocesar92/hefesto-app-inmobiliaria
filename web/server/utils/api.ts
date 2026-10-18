/**
 * Cliente interno hacia la API de Laravel.
 *
 * QUE HACE ESTE ARCHIVO
 * ---------------------
 * El navegador nunca habla directo con la API. Habla con el servidor de Nuxt
 * (el BFF), que reenvia la cookie de sesion. Esa es la unica razon por la que
 * no hay ningun token en el codigo del navegador.
 *
 * POR QUE SE REENVIA LA CABECERA X-XSRF-TOKEN
 * -------------------------------------------
 * Laravel valida el token CSRF en las peticiones que modifican datos. Con el
 * controlador de sesion `web`, el token viaja en la cookie `XSRF-TOKEN` (que se
 * puede leer desde JavaScript) y hay que devolverlo en la cabecera
 * `X-XSRF-TOKEN`. El BFF lee la cookie y pone la cabecera. Si se olvida, todos
 * los POST devuelven 419 y el error se vuelve muy dificil de entender.
 *
 * QUE NO HACE
 * -----------
 * No reintenta, no cachea y no "corrige" errores. Un 404 de la API se devuelve
 * como 404: en una ficha de propiedad, inventar una respuesta para que la
 * pantalla se vea bien seria el error mas grave posible. El 404 tambien es lo
 * que la API devuelve cuando un corredor pide algo de la cartera de otro, y ese
 * 404 es correcto, no un fallo que haya que disimular.
 */

import type { H3Event } from 'h3'

/**
 * Error de la API, con su codigo intacto.
 */
export class ErrorDeApi extends Error {
    constructor(
        public readonly codigo: number,
        public readonly cuerpo: unknown,
        message: string,
    ) {
        super(message)
        this.name = 'ErrorDeApi'
    }
}

/**
 * Llama a la API reenviando la sesion del navegador.
 */
export async function llamarApi<T>(
    evento: H3Event,
    ruta: string,
    opciones: {
        metodo?: 'GET' | 'POST' | 'PATCH' | 'DELETE'
        cuerpo?: unknown
        consulta?: Record<string, string | number | boolean | undefined>
    } = {},
): Promise<T> {
    const config = useRuntimeConfig(evento)
    const metodo = opciones.metodo ?? 'GET'

    const cabeceras: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    }

    const cookies = getRequestHeader(evento, 'cookie') ?? ''

    if (cookies !== '') {
        cabeceras.Cookie = cookies
    }

    const tokenCsrf = leerCookie(evento, 'XSRF-TOKEN')

    if (tokenCsrf !== null) {
        cabeceras['X-XSRF-TOKEN'] = decodeURIComponent(tokenCsrf)
    }

    let cuerpo: string | undefined

    if (opciones.cuerpo !== undefined) {
        cabeceras['Content-Type'] = 'application/json'
        cuerpo = JSON.stringify(opciones.cuerpo)
    }

    const respuesta = await $fetch.raw(`${config.apiBase}/api/v1${ruta}`, {
        method: metodo,
        headers: cabeceras,
        body: cuerpo,
        query: opciones.consulta,
        // Las cookies de la API se emiten en el servidor de Nuxt; por eso hay
        // que copiar las que devuelve la respuesta de Laravel.
        onResponse: async ({ headers }) => {
            for (const cookie of leerSetCookie(headers)) {
                appendResponseHeader(evento, 'set-cookie', cookie)
            }
        },
        // La API devuelve 404 y 422 a proposito (ver bootstrap/app.php). Sin
        // esto, $fetch lanzaria por cualquier estado 4xx y se perderia el
        // cuerpo de la respuesta.
        ignoreResponseError: true,
    })

    const datos = respuesta._data ?? null

    if (respuesta.status >= 400) {
        const mensaje =
            datos && typeof datos === 'object' && 'mensaje' in datos
                ? String((datos as { mensaje: unknown }).mensaje)
                : 'La peticion no se pudo completar.'

        throw new ErrorDeApi(respuesta.status, datos, mensaje)
    }

    return datos as T
}

/**
 * Lee una cookie del navegador que llego al servidor de Nuxt.
 */
function leerCookie(evento: H3Event, nombre: string): string | null {
    const cabecera = getRequestHeader(evento, 'cookie')

    if (! cabecera) {
        return null
    }

    for (const parte of cabecera.split(';')) {
        const [clave, ...resto] = parte.trim().split('=')

        if (clave === nombre) {
            return resto.join('=')
        }
    }

    return null
}

/**
 * Cookies que devuelve la API y hay que reenviar al navegador.
 */
function leerSetCookie(headers: Headers): string[] {
    const cabeceraCruda = headers.get('set-cookie')

    if (! cabeceraCruda) {
        return []
    }

    // En Node, varias cookies llegan separadas por ", " menos cuando el valor
    // contiene una coma (las fechas lo hacen). Se separan por el patron
    // `nombre=valor;` en lugar de por comas a secas.
    return cabeceraCruda.split(/,\s*(?=[^;,\s]+=)/)
}