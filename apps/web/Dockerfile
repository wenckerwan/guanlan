FROM node:22-alpine AS build
WORKDIR /app
COPY . .
RUN npm ci && npm run build

FROM node:22-alpine AS runtime
ENV NODE_ENV=production
WORKDIR /app
COPY --from=build --chown=node:node /app/.output ./
USER node
EXPOSE 3000
CMD ["node", "server/index.mjs"]
