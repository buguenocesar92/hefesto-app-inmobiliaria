/**
 * Sesion de la persona y su alcance.
 *
 * El navegador NO tiene ningun token: la cookie de sesion viaja sola. Esta
 * composable solo lee lo que el servidor de Nuxt ya sabe.
 *
 * POR QUE EL ALCANCE SE DECIDE EN EL SERVIDOR
 * -------------------------------------------
 * `es_administrador` no es un dato que la interfaz deriva para mostrar botones:
 * la API vuelve a comprobar cada permiso. Ocultar un boton es una cortesia, no
 * una proteccion. Ver docs/07.
 */
export interface UsuarioSesion {
    id: string
    nombre: string
    correo: string
    rol: 'administrador' | 'corredor'
    es_administrador: boolean
    /** `null` para el administrador: es el unico que ve toda la inmobiliaria. */
    cartera: string | null
    inmobiliaria: { id: string; nombre: string; slug: string } | null
}

export function useSesion() {
    const usuario = useState<UsuarioSesion | null>('usuario', () => null)
    const cargado = useState<boolean>('usuario-cargado', () => false)

    /**
     * Trae la sesion una sola vez por carga de pagina.
     *
     * Se usa `useRequestFetch` para que el servidor use las cookies de la
     * peticion: con `$fetch` a secas, el render en el servidor saldria sin
     * sesion y la pantalla apareceria vacia en el primer HTML.
     */
    async function cargar(): Promise<UsuarioSesion | null> {
        if (cargado.value) {
            return usuario.value
        }

        try {
            const respuesta = await useRequestFetch()('/api/usuario')
            usuario.value = respuesta as UsuarioSesion
        } catch {
            // Un 401 o 404 aca significa "no hay sesion", no un error que haya
            // que mostrar. La pantalla de entrada se encarga.
            usuario.value = null
        } finally {
            cargado.value = true
        }

        return usuario.value
    }

    async function salir(): Promise<void> {
        try {
            await $fetch('/api/salir', { method: 'POST' })
        } finally {
            usuario.value = null
            await navigateTo('/login')
        }
    }

    return {
        usuario,
        cargado,
        cargar,
        salir,
        esAdministrador: computed(() => usuario.value?.es_administrador === true),
    }
}