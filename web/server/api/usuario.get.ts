import { llamarApi } from '../utils/api'

/**
 * Quien esta conectado, para la interfaz.
 *
 * No devuelve 401 cuando no hay sesion: devuelve `null`, porque la pantalla
 * de entrada necesita distinguir "no hay sesion" de "hay un error".
 */
export default defineEventHandler(async (evento) => {
    try {
        const respuesta = await llamarApi<{ datos: unknown }>(evento, '/auth/usuario')

        return respuesta.datos
    } catch (excepcion) {
        // 401 o 404 significan "no hay sesion". Cualquier otro si es un error
        // y conviene que se vea.
        if (
            excepcion &&
            typeof excepcion === 'object' &&
            'codigo' in excepcion &&
            (excepcion as { codigo: number }).codigo === 404
        ) {
            return null
        }

        throw excepcion
    }
})