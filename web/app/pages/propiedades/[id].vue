<script setup lang="ts">
/**
 * Ficha de una propiedad, con la linea de tiempo de sus estados.
 *
 * LA LINEA DE TIEMPO
 * ------------------
 * Cada tramo es un periodo que la propiedad paso en un estado. El tramo abierto,
 * el ultimo, es el estado en que esta ahora.
 *
 * Se muestra de mas reciente a mas antigua, y de los automaticos se dice que los
 * hizo el sistema y por que. Un tramo automatico con nombre de corredor seria
 * mentira.
 */
const ruta = useRoute()
const id = ruta.params.id as string

const { usuario, cargar } = useSesion()
await cargar()

interface Tramo {
    id: string
    estado_anterior: { id: string; nombre: string; color: string } | null
    estado_nuevo: { id: string; nombre: string; color: string }
    desde: string
    hasta: string | null
    dias: number
    duracion_final: number | null
    abierto: boolean
    es_automatico: boolean
    usuario: { id: string; nombre: string } | null
    motivo: string | null
}

interface RespuestaHistorial {
    datos: Tramo[]
    oferta_id: string
    tipo_operacion: string
    dias_en_estado_actual: number
}

const error = ref<string | null>(null)
const cargando = ref(true)
const historiales = ref<RespuestaHistorial[]>([])

interface EstadoOferta {
    oferta_id: string
    estado_id: string
    estado_nombre: string | null
    estado_color: string | null
    estado_desde: string | null
    dias_en_estado: number
    valor_lista: number | null
}

const ficha = ref<{
    id: string
    direccion: string
    comuna: string
    tipo_propiedad: string
    caracteristicas: Record<string, unknown>
    valor_venta: number | null
    valor_arriendo: number | null
    estado_venta: EstadoOferta | null
    estado_arriendo: EstadoOferta | null
} | null>(null)

async function cargar() {
    cargando.value = true
    error.value = null

    try {
        const respuesta = await $fetch<{ datos: typeof ficha.value }>(`/api/propiedades/${id}`)
        ficha.value = respuesta.datos

        // Una propiedad puede estar en venta y en arriendo a la vez, y cada
        // oferta tiene su propia linea de tiempo. Se piden las dos, siempre.
        const ofertas: string[] = []

        if (ficha.value?.estado_venta) ofertas.push(ficha.value.estado_venta.oferta_id)
        if (ficha.value?.estado_arriendo) ofertas.push(ficha.value.estado_arriendo.oferta_id)

        historiales.value = await Promise.all(
            ofertas.map((ofertaId) =>
                $fetch<RespuestaHistorial>(`/api/propiedades/${id}/ofertas/${ofertaId}/historial`),
            ),
        )
    } catch (excepcion) {
        error.value =
            excepcion instanceof Error
                ? excepcion.message
                : 'No se pudo cargar la propiedad.'
    } finally {
        cargando.value = false
    }
}

function formatoFecha(iso: string | null): string {
    if (iso === null) {
        return '—'
    }

    return new Intl.DateTimeFormat('es-CL', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(new Date(iso))
}

function formatoDinero(valor: number | null): string {
    if (valor === null) {
        return '—'
    }

    return new Intl.NumberFormat('es-CL', {
        style: 'currency',
        currency: 'CLP',
        maximumFractionDigits: 0,
    }).format(valor)
}

const TIPO_OPERACION: Record<string, string> = {
    venta: 'Venta',
    arriendo: 'Arriendo',
}
</script>

<template>
    <div>
        <p v-if="cargando" class="aviso">Cargando...</p>
        <p v-if="error" class="aviso aviso--error">{{ error }}</p>

        <template v-if="ficha">
            <header class="ficha__encabezado">
                <div>
                    <h1 class="ficha__titulo">{{ ficha.direccion }}</h1>
                    <p class="ficha__subtitulo">
                        {{ ficha.comuna }} · {{ ficha.tipo_propiedad }}
                    </p>
                </div>

                <div class="ficha__precios">
                    <div v-if="ficha.valor_venta">
                        <span class="ficha__etiqueta">Venta</span>
                        <span>{{ formatoDinero(ficha.valor_venta) }}</span>
                    </div>
                    <div v-if="ficha.valor_arriendo">
                        <span class="ficha__etiqueta">Arriendo</span>
                        <span>{{ formatoDinero(ficha.valor_arriendo) }}</span>
                    </div>
                </div>
            </header>

            <section
                v-for="(historial, indice) in historiales"
                :key="historial.oferta_id"
                class="linea"
                :aria-label="`Historial de ${TIPO_OPERACION[historial.tipo_operacion] ?? historial.tipo_operacion}`"
            >
                <h2 class="linea__titulo">
                    {{ TIPO_OPERACION[historial.tipo_operacion] ?? historial.tipo_operacion }}
                </h2>

                <ol class="tramos">
                    <li
                        v-for="tramo in historial.datos"
                        :key="tramo.id"
                        class="tramo"
                        :class="{ 'tramo--abierto': tramo.abierto }"
                    >
                        <span class="punto" :style="{ background: tramo.estado_nuevo.color }" aria-hidden="true" />

                        <div class="tramo__cuerpo">
                            <p class="tramo__estado">
                                {{ tramo.estado_nuevo.nombre }}
                                <span v-if="tramo.abierto" class="tramo__vigente">en curso</span>
                            </p>

                            <p class="tramo__fechas">
                                {{ formatoFecha(tramo.desde) }} → {{ tramo.hasta ? formatoFecha(tramo.hasta) : 'hoy' }}
                                <span class="tramo__dias">
                                    {{ tramo.dias }} dia(s)
                                </span>
                            </p>

                            <p class="tramo__motivo">
                                <template v-if="tramo.es_automatico">
                                    <span class="tramo__sistema">Cambio del sistema</span>
                                    {{ tramo.motivo }}
                                </template>
                                <template v-else>
                                    {{ tramo.usuario?.nombre ?? 'Sin responsable' }}
                                    <template v-if="tramo.motivo"> · {{ tramo.motivo }}</template>
                                </template>
                            </p>
                        </div>
                    </li>
                </ol>
            </section>

            <p v-if="historiales.length === 0" class="aviso">
                Esta propiedad todavia no tiene ofertas, asi que no hay historial que mostrar.
            </p>
        </template>
    </div>
</template>

<style scoped>
.ficha__encabezado {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 2rem;
}

.ficha__titulo {
    margin: 0 0 0.25rem;
    font-size: 1.3rem;
}

.ficha__subtitulo {
    margin: 0;
    color: var(--color-texto-suave);
    font-size: 0.9rem;
}

.ficha__precios {
    display: flex;
    gap: 1.5rem;
    font-variant-numeric: tabular-nums;
}

.ficha__etiqueta {
    display: block;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--color-texto-tenue);
}

.linea {
    margin-bottom: 2rem;
}

.linea__titulo {
    font-size: 1rem;
    margin: 0 0 0.75rem;
}

.tramos {
    list-style: none;
    margin: 0;
    padding: 0;
    border-left: 2px solid var(--color-borde);
}

.tramo {
    display: flex;
    gap: 0.75rem;
    padding: 0.6rem 0 0.6rem 1rem;
    position: relative;
}

.punto {
    position: absolute;
    left: -0.4rem;
    top: 1rem;
    width: 0.7rem;
    height: 0.7rem;
    border-radius: 50%;
    border: 2px solid var(--color-fondo);
}

.tramo__cuerpo {
    flex: 1;
}

.tramo__estado {
    margin: 0;
    font-weight: 600;
}

.tramo__vigente {
    margin-left: 0.5rem;
    font-size: 0.7rem;
    font-weight: 400;
    padding: 0.1rem 0.4rem;
    border-radius: 4px;
    background: var(--color-alerta-fondo);
    color: var(--color-alerta);
}

.tramo__fechas,
.tramo__motivo {
    margin: 0.2rem 0 0;
    font-size: 0.82rem;
    color: var(--color-texto-tenue);
}

.tramo__dias {
    margin-left: 0.5rem;
    font-variant-numeric: tabular-nums;
}

.tramo__sistema {
    margin-right: 0.4rem;
    padding: 0.05rem 0.35rem;
    border-radius: 3px;
    background: var(--color-borde);
    color: var(--color-texto-suave);
    font-size: 0.75rem;
}
</style>