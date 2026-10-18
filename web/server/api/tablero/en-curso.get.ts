import { llamarApi } from '../../utils/api'

/**
 * Tablero: propiedades en su estado actual, de mas antigua a mas reciente.
 */
export default defineEventHandler((evento) => {
    const consulta = getQuery(evento)

    return llamarApi(evento, '/tablero/en-curso', {
        consulta: {
            tipo_operacion: (consulta.tipo_operacion as string) ?? 'venta',
            comuna: (consulta.comuna as string) || undefined,
            limite: (consulta.limite as string) || undefined,
        },
    })
})