# Interfaz web (vinext / React). La API se llama en la misma dirección (/api/v1) a través del proxy,
# así la imagen sirve para cualquier dominio sin volver a construirla.
FROM node:22-bookworm-slim AS build
WORKDIR /app
COPY app/package.json app/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY app/ ./
ARG NEXT_PUBLIC_API_URL=/api/v1
ENV NEXT_PUBLIC_API_URL=${NEXT_PUBLIC_API_URL}
RUN rm -f .env .env.local && printf 'NEXT_PUBLIC_API_URL=%s\n' "$NEXT_PUBLIC_API_URL" > .env.production && npm run build

FROM node:22-bookworm-slim
ENV NODE_ENV=production PORT=3000
WORKDIR /app
COPY --from=build /app ./
EXPOSE 3000
CMD ["npx", "vinext", "start", "-p", "3000"]
