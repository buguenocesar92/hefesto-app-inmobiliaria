<script setup lang="ts">
/**
 * Marco de la aplicacion.
 *
 * La barra lateral muestra el alcance de la persona. Para un corredor dice
 * "Mi cartera"; para el administrador, "Toda la inmobiliaria".
 *
 * OCULTAR UN ENLACE NO ES UNA PROTECCION
 * --------------------------------------
 * Que el boton de administracion no aparezca a un corredor es una cortesia. La
 * API vuelve a comprobar cada permiso en cada peticion, y si alguien abre la
 * URL a mano recibe 403. El unico filtro que sostiene de verdad es el del
 * servidor. Ver docs/07.
 */
const { usuario, cargar, salir } = useSesion()

await cargar()

const ruta = useRoute()

const enlaces = computed(() => {
    const base = [
        { to: '/tablero', texto: 'Tablero' },
        { to: '/propiedades', texto: 'Propiedades' },
        { to: '/clientes', texto: 'Clientes' },
        { to: '/visitas', texto: 'Visitas' },
    ]

    if (usuario.value?.es_administrador === true) {
        base.push({ to: '/operaciones', texto: 'Operaciones' })
        base.push({ to: '/admin/estados', texto: 'Estados' })
    }

    return base
})

function estaActivo(to: string): boolean {
    return ruta.path === to || ruta.path.startsWith(`${to}/`)
}
</script>

<template>
    <div class="marco">
        <aside class="marco__lateral">
            <div class="marco__marca">
                <span class="marco__nombre">Hefesto Inmobiliaria</span>
                <span v-if="usuario?.inmobiliaria" class="marco__inquilino">
                    {{ usuario.inmobiliaria.nombre }}
                </span>
            </div>

            <nav class="marco__navegacion">
                <NuxtLink
                    v-for="enlace in enlaces"
                    :key="enlace.to"
                    :to="enlace.to"
                    class="marco__enlace"
                    :class="{ 'marco__enlace--activo': estaActivo(enlace.to) }"
                >
                    {{ enlace.texto }}
                </NuxtLink>
            </nav>

            <div class="marco__pie">
                <template v-if="usuario">
                    <p class="marco__persona">{{ usuario.nombre }}</p>
                    <p class="marco__alcance">
                        {{ usuario.es_administrador ? 'Toda la inmobiliaria' : 'Mi cartera' }}
                    </p>
                    <button class="boton boton--chico" type="button" @click="salir">Salir</button>
                </template>
            </div>
        </aside>

        <main class="marco__contenido">
            <slot />
        </main>
    </div>
</template>

<style scoped>
.marco {
    display: grid;
    grid-template-columns: 15rem 1fr;
    min-height: 100vh;
}

.marco__lateral {
    border-right: 1px solid var(--color-borde);
    padding: 1.25rem 1rem;
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    background: var(--color-fondo);
}

.marco__marca {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}

.marco__nombre {
    font-weight: 700;
    font-size: 0.95rem;
}

.marco__inquilino {
    font-size: 0.78rem;
    color: var(--color-texto-tenue);
}

.marco__navegacion {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}

.marco__enlace {
    padding: 0.45rem 0.6rem;
    border-radius: 6px;
    text-decoration: none;
    color: inherit;
    font-size: 0.9rem;
}

.marco__enlace:hover {
    background: var(--color-superficie);
}

.marco__enlace--activo {
    background: var(--color-superficie);
    font-weight: 600;
}

.marco__pie {
    margin-top: auto;
    border-top: 1px solid var(--color-borde);
    padding-top: 0.9rem;
}

.marco__persona {
    margin: 0;
    font-size: 0.85rem;
    font-weight: 600;
}

.marco__alcance {
    margin: 0 0 0.6rem;
    font-size: 0.75rem;
    color: var(--color-texto-tenue);
}

.marco__contenido {
    padding: 1.75rem 2rem;
    max-width: 80rem;
}

@media (max-width: 720px) {
    .marco {
        grid-template-columns: 1fr;
    }

    .marco__lateral {
        border-right: none;
        border-bottom: 1px solid var(--color-borde);
    }
}
</style>