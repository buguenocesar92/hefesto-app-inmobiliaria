import { llamarApi } from '../utils/api'

/**
 * Redirige al proveedor de identidad.
 *
 * Es una redireccion simple, no una llamada JSON: el navegador tiene que
 * navegar a Authentik, no recibir un error de red.
 */
export default defineEventHandler((evento) => {
    return sendRedirect(evento, '/api/v1/auth/login')
})