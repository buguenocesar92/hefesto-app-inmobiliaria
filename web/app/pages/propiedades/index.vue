<script setup lang="ts">
/**
 * Listado de propiedades.
 *
 * Cada fila muestra los DOS estados, porque una propiedad puede estar en venta y
 * en arriendo al mismo tiempo y son dos historias distintas. La celda de un
 * mercado en el que la propiedad no esta dice "no esta", que no es lo mismo que
 * un estado en blanco.
 */
const { cargar: cargarSesion } = useSesion()
await cargarSesion()

const filtros = reactive({
    buscar: '',
    comuna: '',
    tipo_operacion: '',
})

const cargando = ref(true)
const error = ref<string | null>(null)

interface EstadoOferta {
    oferta_id: string
    estado_nombre: string | null
    estado_color: string | null
    dias_en_estado: number
}

interface Fila {
    id: string
    direccion: string
    comuna: string
    tipo_propiedad: string
    valor_venta: number | null
    valor_arriendo: number | null
    corredor: { id: string; nombre: string } | null
    estado_venta: EstadoOferta | null
    estado_arriendo: EstadoOferta | null
}

interface Respuesta {
    datos: Fila[]
    meta: { pagina_actual: number; por_pagina: number; total: number; ultima_pagina: number }
}

const filas = ref<Fila[]>([])
const meta = ref<Respuesta['meta'] | null>(null)

async function cargar() {
    cargando.value = true
    error.value = null

    try {
        const respuesta = await $fetch<Respuesta>('/api/propiedades', {
            query: {
                buscar: filtros.buscar || undefined,
                comuna: filtros.comuna || undefined,
                tipo_operacion: filtros.tipo_operacion || undefined,
            },
        })

        filas.value = respuesta.datos
        meta.value = respuesta.meta
    } catch (excepcion) {
        error.value = excepcion instanceof Error ? excepcion.message : 'No se pudieron cargar las propiedades.'
    } finally {
        cargando.value = false
    }
}

onMounted(cargar)

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
</script>

<template>
    <div>
        <header class="encabezado">
            <div>
                <h1 class="titulo">Propiedades</h1>
                <p class="subtitulo">
                    {{ meta?.total ?? 0 }} en tu alcance. Cada fila muestra los dos mercados por
                    separado.
                </p>
            </div>

            <div class="filtros">
                <input v-model="filtros.buscar" class="filtros__campo" placeholder="Direccion" @change="cargar" />
                <input v-model="filtros.comuna" class="filtros__campo" placeholder="Comuna" @change="cargar" />
                <select v-model="filtros.tipo_operacion" class="filtros__campo" @change="cargar">
                    <option value="">Todos los mercados</option>
                    <option value="venta">Solo venta</option>
                    <option value="arriendo">Solo arriendo</option>
                </select>
            </div>
        </header>

        <p v-if="cargando" class="aviso">Cargando...</p>
        <p v-if="error" class="aviso aviso--error">{{ error }}</p>

        <table v-if="!cargando && filas.length > 0" class="tabla">
            <thead>
                <tr>
                    <th scope="col">Direccion</th>
                    <th scope="col">Comuna</th>
                    <th scope="col">Venta</th>
                    <th scope="col">Arriendo</th>
                    <th scope="col">Corredor</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="fila in filas" :key="fila.id">
                    <td>
                        <NuxtLink :to="`/propiedades/${fila.id}`" class="tabla__enlace">
                            {{ fila.direccion }}
                        </NuxtLink>
                    </td>
                    <td>{{ fila.comuna }}</td>
                    <td>
                        <template v-if="fila.estado_venta">
                            <span class="punto" :style="{ background: fila.estado_venta.estado_color }" />
                            {{ fila.estado_venta.estado_nombre }}
                            <span class="tabla__dias">{{ fila.estado_venta.dias_en_estado }} d</span>
                            <span class="tabla__precio">{{ formatoDinero(fila.valor_venta) }}</span>
                        </template>
                        <span v-else class="tabla__vacio">no esta en venta</span>
                    </td>
                    <td>
                        <template v-if="fila.estado_arriendo">
                            <span class="punto" :style="{ background: fila.estado_arriendo.estado_color }" />
                            {{ fila.estado_arriendo.estado_nombre }}
                            <span class="tabla__dias">{{ fila.estado_arriendo.dias_en_estado }} d</span>
                            <span class="tabla__precio">{{ formatoDinero(fila.valor_arriendo) }}</span>
                        </template>
                        <span v-else class="tabla__vacio">no esta en arriendo</span>
                    </td>
                    <td>{{ fila.corredor?.nombre ?? '—' }}</td>
                </tr>
            </tbody>
        </table>

        <p v-else-if="!cargando" class="aviso">No hay propiedades que coincidan con el filtro.</p>
    </div>
</template>

<style scoped>
.encabezado {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    justify-content: space-between;
    align-items: flex-end;
    margin-bottom: 1.5rem;
}

.titulo {
    margin: 0 0 0.25rem;
    font-size: 1.3rem;
}

.subtitulo {
    margin: 0;
    font-size: 0.9rem;
    color: var(--color-texto-suave);
}

.tabla {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
}

.tabla th,
.tabla td {
    text-align: left;
    padding: 0.6rem 0.75rem;
    border-bottom: 1px solid var(--color-borde);
    vertical-align: top;
}

.tabla th {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--color-texto-tenue);
}

.tabla__enlace {
    text-decoration: none;
    border-bottom: 1px solid transparent;
}

.tabla__enlace:hover {
    border-bottom-color: currentColor;
}

.tabla__dias {
    margin-left: 0.4rem;
    color: var(--color-texto-tenue);
    font-variant-numeric: tabular-nums;
}

.tabla__precio {
    display: block;
    font-size: 0.78rem;
    color: var(--color-texto-tenue);
}

.tabla__vacio {
    color: var(--color-texto-tenue);
    font-style: italic;
}

.punto {
    display: inline-block;
    width: 0.6rem;
    height: 0.6rem;
    border-radius: 50%;
    margin-right: 0.3rem;
}
</style>