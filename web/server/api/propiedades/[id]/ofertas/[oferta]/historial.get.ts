import { llamarApi } from '../../utils/api'

/**
 * Historial de estados de UNA oferta.
 *
 * Se pide por oferta y no por propiedad a proposito: una propiedad en venta y
 * en arriendo tiene DOS historiales distintos, y mezclarlos seria mostrar un
 * estado de venta junto a uno de arriendo como si fueran la misma linea de
 * tiempo.
 */
export default defineEventHandler((evento) => {
    const propiedadId = getRouterParam(evento, 'id')
    const ofertaId = getRouterParam(evento, 'oferta')

    return llamarApi(evento, `/ofertas/${ofertaId}/historial`)
})