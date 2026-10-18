import { llamarApi } from '../../utils/api'

/**
 * Ficha de una propiedad.
 *
 * Un 404 aca puede significar dos cosas y las dos son correctas: que la
 * propiedad no existe, o que existe pero es de otra cartera. La API no las
 * distingue a proposito, y el BFF tampoco: distinguirlas seria confirmarle a
 * quien pregunta que la propiedad existe. Ver docs/03.
 */
export default defineEventHandler((evento) => {
    const id = getRouterParam(evento, 'id')

    return llamarApi(evento, `/propiedades/${id}`)
})