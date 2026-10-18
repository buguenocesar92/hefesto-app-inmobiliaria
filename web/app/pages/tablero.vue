<script setup lang="ts">
/**
 * EL TABLERO: la pantalla que justifica la aplicacion.
 *
 * Muestra, por estado y por tipo de operacion, tres numeros:
 *
 *   - MEDIANA: el caso tipico. No se distorce con una reserva eterna.
 *   - P90: el mal caso. Si una reserva se pasa de tu propio p90, es anormal
 *     para tu negocio y no para el mercado.
 *   - EN CURSO: cuantas propiedades HOY estan en ese estado, y la mas antigua
 *     de ellas. Es la fila que hace saltar la campana.
 *
 * Y debajo, la lista de las que llevan mas tiempo en su estado, ordenadas de mas
 * antigua a mas reciente. El orden de esa lista ES el diagnostico: la primera
 * fila es, casi siempre, una reserva olvidada.
 *
 * Un corredor ve SUS numeros. El administrador ve los de toda la inmobiliaria y
 * puede filtrar por corredor.
 */
import type { UsuarioSesion } from '~/composables/useSesion'

interface FilaPermanencia {
    estado_id: string
    nombre: string
    color: string
    tramos_cerrados: number
    promedio_dias: number | null
    mediana_dias: number | null
    p90_dias: number | null
    maximo_dias: number | null
    en_curso: number
    dias_del_mas_antiguo: number | null
}

interface FilaEnCurso {
    oferta_id: string
    propiedad_id: string
    estado_id: string
    direccion: string
    comuna: string
    corredor: string | null
    estado: string
    estado_color: string
    dias_en_estado: number
}

const { usuario, cargar } = useSesion()

await cargar()

if (usuario.value === null) {
    await navigateTo('/login')
}

const config = useRuntimeConfig()

const tipoOperacion = ref<'venta' | 'arriendo'>('venta')
const comuna = ref('')
const corredorId = ref('')

const cargaPendiente = ref(false)
const error = ref<string | null>(null)

interface RespuestaPermanencia {
    tipo_operacion: string
    comuna: string | null
    alcance: string
    datos: FilaPermanencia[]
}

interface RespuestaEnCurso {
    tipo_operacion: string
    datos: FilaEnCurso[]
    total: number
}

const permanencia = ref<RespuestaPermanencia | null>(null)
const enCurso = ref<RespuestaEnCurso | null>(null)

/**
 * Dias a partir de los cuales una reserva se considera colgada.
 *
 * El mismo criterio que el backend usa para la campana. Si se pidiera al
 * servidor por cada fila seria una llamada por celda.
 */
const DIAS_COLGADA = 30

const consulta = computed(() => ({
    tipo_operacion: tipoOperacion.value,
    comuna: comuna.value || undefined,
    corredor_id: corredorId.value || undefined,
}))

async function cargarTablero() {
    cargaPendiente.value = true
    error.value = null

    try {
        const [p, e] = await Promise.all([
            $fetch<RespuestaPermanencia>('/api/tablero/permanencia', { query: consulta.value }),
            $fetch<RespuestaEnCurso>('/api/tablero/en-curso', {
                query: {
                    tipo_operacion: tipoOperacion.value,
                    comuna: comuna.value || undefined,
                    limite: config.public.filasEnCurso,
                },
            }),
        ])

        permanencia.value = p
        enCurso.value = e
    } catch (excepcion) {
        // Un 404 aca no es un fallo de red: la API responde 404 cuando el
        // corredor pide algo fuera de su cartera, y tambien cuando no hay
        // sesion. Se muestra el mensaje que vino.
        error.value =
            excepcion instanceof Error ? excepcion.message : 'No se pudo cargar el tablero.'
    } finally {
        cargaPendiente.value = false
    }
}

onMounted(cargarTablero)
watch([tipoOperacion], cargarTablero)

/**
 * Filas que merecen una campana: las que en curso superan los dias de alarma.
 *
 * Solo se marcan las que supera los dias de alarma del tipo de operacion.
 */
function esColgada(fila: FilaEnCurso): boolean {
    if (tipoOperacion.value === 'venta') {
        return fila.dias_en_estado >= DIAS_COLGADA
    }

    return false
}

function formatoDias(valor: number | null): string {
    if (valor === null) {
        return '—'
    }

    if (valor >= 365) {
        const anos = Math.floor(valor / 365)
        const meses = Math.round((valor % 365) / 30)

        return meses > 0 ? `${anos} a ${meses} m` : `${anos} a`
    }

    if (valor >= 60) {
        return `${Math.round(valor / 30)} meses`
    }

    return `${Math.round(valor)} d`
}

</script>

<template>
    <div class="tablero">
        <header class="tablero__encabezado">
            <div>
                <h1 class="tablero__titulo">Permanencia por estado</h1>
                <p class="tablero__subtitulo">
                    Cuanto tiempo lleva cada propiedad en cada estado. Un promedio solo engana:
                    mira tambien la mediana y el p90.
                </p>
            </div>

            <nav class="filtros">
                <div class="filtros__grupo" role="group" aria-label="Tipo de operacion">
                    <button
                        v-for="tipo in ['venta', 'arriendo'] as const"
                        :key="tipo"
                        class="filtros__boton"
                        :class="{ 'filtros__boton--activo': tipoOperacion === tipo }"
                        :aria-pressed="tipoOperacion === tipo"
                        @click="tipoOperacion = tipo"
                    >
                        {{ tipo === 'venta' ? 'Venta' : 'Arriendo' }}
                    </button>
                </div>

                <input
                    v-model="comuna"
                    class="filtros__campo"
                    type="text"
                    placeholder="Comuna (opcional)"
                    @change="cargarTablero"
                />

                <select
                    v-if="usuario?.es_administrador"
                    v-model="corredorId"
                    class="filtros__campo"
                    @change="cargarTablero"
                >
                    <option value="">Toda la inmobiliaria</option>
                </select>
            </nav>
        </header>

        <p v-if="cargaPendiente" class="aviso">Cargando...</p>
        <p v-if="error" class="aviso aviso--error">{{ error }}</p>

        <template v-if="permanencia">
            <section class="rejilla" aria-label="Permanencia por estado">
                <article v-for="fila in permanencia.datos" :key="fila.estado_id" class="tarjeta">
                    <header class="tarjeta__encabezado">
                        <span class="punto" :style="{ background: fila.color }" aria-hidden="true" />
                        <h2 class="tarjeta__titulo">{{ fila.nombre }}</h2>
                    </header>

                    <dl class="medidas">
                        <div class="medida">
                            <dt>Mediana</dt>
                            <dd>{{ formatoDias(fila.mediana_dias) }}</dd>
                        </div>
                        <div class="medida">
                            <dt>p90</dt>
                            <dd>{{ formatoDias(fila.p90_dias) }}</dd>
                        </div>
                        <div class="medida">
                            <dt>Promedio</dt>
                            <dd>{{ formatoDias(fila.promedio_dias) }}</dd>
                        </div>
                    </dl>

                    <footer class="tarjeta__pie">
                        <span>{{ fila.tramos_cerrados }} cerrado(s)</span>
                        <span v-if="fila.en_curso > 0" class="tarjeta__curso">
                            {{ fila.en_curso }} en curso
                            <template v-if="fila.dias_del_mas_antiguo !== null">
                                · la mas antigua, {{ formatoDias(fila.dias_del_mas_antiguo) }}
                            </template>
                        </span>
                    </footer>
                </article>
            </section>

            <section class="en-curso" aria-label="Propiedades en su estado actual">
                <h2 class="en-curso__titulo">
                    Propiedades en curso
                    <span class="en-curso__conteo">{{ enCurso?.total ?? 0 }}</span>
                </h2>

                <p v-if="(enCurso?.datos.length ?? 0) === 0" class="aviso">
                    No hay propiedades en este estado.
                </p>

                <table v-else class="tabla">
                    <thead>
                        <tr>
                            <th scope="col">Direccion</th>
                            <th scope="col">Comuna</th>
                            <th scope="col">Estado</th>
                            <th scope="col">Corredor</th>
                            <th scope="col" class="tabla__numero">En el estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="fila in enCurso?.datos"
                            :key="fila.oferta_id"
                            :class="{ 'tabla__fila--alerta': esColgada(fila) }"
                        >
                            <td>
                                <NuxtLink :to="`/propiedades/${fila.propiedad_id}`" class="tabla__enlace">
                                    {{ fila.direccion }}
                                </NuxtLink>
                            </td>
                            <td>{{ fila.comuna }}</td>
                            <td>
                                <span class="punto" :style="{ background: fila.estado_color }" aria-hidden="true" />
                                {{ fila.estado }}
                            </td>
                            <td>{{ fila.corredor ?? '—' }}</td>
                            <td class="tabla__numero">
                                {{ formatoDias(fila.dias_en_estado) }}
                                <span v-if="esColgada(fila)" class="etiqueta">colgada</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </template>
    </div>
</template>

<style scoped>
.tablero__encabezado {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    align-items: flex-end;
    justify-content: space-between;
    margin-bottom: 1.5rem;
}

.tablero__titulo {
    margin: 0 0 0.25rem;
    font-size: 1.35rem;
}

.tablero__subtitulo {
    margin: 0;
    color: var(--color-texto-suave);
    font-size: 0.9rem;
    max-width: 46ch;
}

.rejilla {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(15rem, 1fr));
    gap: 1rem;
    margin-bottom: 2.5rem;
}

.tarjeta {
    border: 1px solid var(--color-borde);
    border-radius: 10px;
    padding: 1rem;
    background: var(--color-superficie);
}

.tarjeta__encabezado {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}

.tarjeta__titulo {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
}

.punto {
    display: inline-block;
    width: 0.65rem;
    height: 0.65rem;
    border-radius: 50%;
    flex-shrink: 0;
}

.medidas {
    display: flex;
    justify-content: space-between;
    gap: 0.5rem;
    margin: 0 0 0.75rem;
}

.medida dt {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--color-texto-tenue);
}

.medida dd {
    margin: 0.15rem 0 0;
    font-size: 1.1rem;
    font-variant-numeric: tabular-nums;
}

.tarjeta__pie {
    display: flex;
    justify-content: space-between;
    font-size: 0.8rem;
    color: var(--color-texto-tenue);
    border-top: 1px solid var(--color-borde);
    padding-top: 0.6rem;
}

.tarjeta__curso {
    color: var(--color-alerta);
    font-weight: 600;
}

.en-curso__titulo {
    font-size: 1.05rem;
    margin: 0 0 0.75rem;
}

.en-curso__conteo {
    font-weight: 400;
    color: var(--color-texto-tenue);
}

.tabla {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}

.tabla th,
.tabla td {
    text-align: left;
    padding: 0.6rem 0.75rem;
    border-bottom: 1px solid var(--color-borde);
}

.tabla th {
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--color-texto-tenue);
    font-weight: 600;
}

.tabla__numero {
    text-align: right;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.tabla__fila--alerta {
    background: var(--color-alerta-fondo);
}

.tabla__enlace {
    color: inherit;
    text-decoration: none;
    border-bottom: 1px solid transparent;
}

.tabla__enlace:hover {
    border-bottom-color: currentColor;
}

.etiqueta {
    display: inline-block;
    margin-left: 0.5rem;
    padding: 0.1rem 0.4rem;
    border-radius: 4px;
    font-size: 0.7rem;
    background: var(--color-alerta);
    color: #fff;
}
</style>
