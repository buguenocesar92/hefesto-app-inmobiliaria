<script setup lang="ts">
/**
 * Pantalla de entrada. La API redirige al proveedor de identidad.
 *
 * No hay formulario con usuario y contraseno, y no es una omision: la
 * identidad vive en Authentik y esta aplicacion no guarda contrasenos. Ver
 * docs/07.
 */
const { usuario, cargar } = useSesion()

onMounted(async () => {
    const actual = await cargar()

    if (actual !== null) {
        await navigateTo('/tablero')
    }
})
</script>

<template>
    <div class="entrada">
        <div class="entrada__tarjeta">
            <h1 class="entrada__titulo">Hefesto Inmobiliaria</h1>

            <p class="entrada__descripcion">
                Gestion de propiedades, ventas y arriendos, con la medida de
                cuanto tiempo lleva cada propiedad en cada estado.
            </p>

            <a class="boton boton--principal" href="/api/entrar">Entrar con la identidad de Hefesto</a>

            <p class="entrada__nota">
                El acceso lo administra Authentik. Si tu cuenta no pertenece a
                ninguna inmobiliaria habilitada, la pantalla te lo dira.
            </p>
        </div>
    </div>
</template>

<style scoped>
.entrada {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 2rem;
}

.entrada__tarjeta {
    max-width: 30rem;
    text-align: center;
}

.entrada__titulo {
    font-size: 1.6rem;
    margin: 0 0 0.75rem;
}

.entrada__descripcion {
    color: var(--color-texto-suave);
    margin: 0 0 1.75rem;
    line-height: 1.6;
}

.entrada__nota {
    margin-top: 1.5rem;
    font-size: 0.85rem;
    color: var(--color-texto-tenue);
    line-height: 1.5;
}
</style>