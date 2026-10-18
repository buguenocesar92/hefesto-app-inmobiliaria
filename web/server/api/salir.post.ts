import { llamarApi } from '../utils/api'

/**
 * Cierra la sesion.
 *
 * Invalida la sesion en el servidor y regenera el token. El `finally` importa:
 * si la llamada falla, la persona igual vuelve a la pantalla de entrada, porque
 * quedarse en una pantalla a medio cerrar es peor que un error visible.
 */
export default defineEventHandler(async (evento) => {
    try {
        return await llamarApi(evento, '/auth/logout', { metodo: 'POST' })
    } finally {
        await sendRedirect(evento, '/login')
    }
})