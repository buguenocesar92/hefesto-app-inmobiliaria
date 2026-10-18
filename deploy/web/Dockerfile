# =============================================================================
# Hefesto Studio — Hefesto Inmobiliaria: interfaz (Nuxt con render en servidor)
# =============================================================================
# Node con version exacta. Nuxt se compila en una etapa aparte para que la
# imagen final no traiga el codigo fuente ni las dependencias de desarrollo.
# =============================================================================

# -----------------------------------------------------------------------------
# Compilacion
# -----------------------------------------------------------------------------
FROM docker.io/library/node:22.20.0-bookworm-slim AS build

WORKDIR /app

# Primero el manifiesto, para que `npm ci` se re-ejecute solo cuando cambian las
# dependencias y no en cada cambio de codigo.
COPY package*.json ./

# `npm ci` y no `npm install`: `ci` instala EXACTAMENTE lo que dice el
# lockfile y falla si el manifiesto y el lock no coinciden. Con `install`, un
# lock desactualizado se "arregla" solo y el build deja de ser reproducible.
RUN npm ci

COPY . .

RUN npm run build

# -----------------------------------------------------------------------------
# Ejecucion
# -----------------------------------------------------------------------------
FROM docker.io/library/node:22.20.0-bookworm-slim AS runtime

WORKDIR /app

ENV NODE_ENV=production
ENV NITRO_HOST=0.0.0.0
ENV NITRO_PORT=3000

# Solo la salida compilada. La imagen final no tiene codigo fuente ni
# dependencias de desarrollo, que es lo que reduce la superficie de ataque.
COPY --from=build /app/.output ./.output

# El usuario `node` viene en la imagen oficial. Correr como root seria
# innecesario y convertiria un compromiso en un compromiso total.
USER node

EXPOSE 3000

CMD ["node", ".output/server/index.mjs"]