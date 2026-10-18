import { llamarApi } from '../../utils/api'

/**
 * Listado de propiedades.
 *
 * El filtro por cartera lo pone la API, no el BFF: el corredor ve su cartera
 * porque la consulta y la policy lo dicen, no porque el servidor de Nuxt
 * esconda filas. Un filtro en el cliente es un filtro que se puede saltar.
 */
export default defineEventHandler((evento) => {
    const consulta = getQuery(evento)

    return llamarApi(evento, '/propiedades', {
        consulta: {
            comuna: (consulta.comuna as string) || undefined,
            tipo_propiedad: (consulta.tipo_propiedad as string) || undefined,
            tipo_operacion: (consulta.tipo_operacion as string) || undefined,
            corredor_id: (consulta.corredor_id as string) || undefined,
            buscar: (consulta.buscar as string) || undefined,
        },
    })
})