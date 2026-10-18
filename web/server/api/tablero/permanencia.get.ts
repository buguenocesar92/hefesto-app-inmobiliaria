import { llamarApi } from '../../utils/api'

/**
 * Tablero: permanencia por estado.
 *
 * El BFF no reescribe ni arregla nada de lo que llega. Si la API responde 403 o
 * 404, eso viaja tal cual a la pantalla: un tablero con datos inventados para
 * que se vea bien seria peor que un tablero con un error. Ver docs/01.
 */
export default defineEventHandler((evento) => {
    const consulta = getQuery(evento)

    return llamarApi(evento, '/tablero/permanencia', {
        consulta: {
            tipo_operacion: (consulta.tipo_operacion as string) ?? 'venta',
            comuna: (consulta.comuna as string) || undefined,
            corredor_id: (consulta.corredor_id as string) || undefined,
        },
    })
})