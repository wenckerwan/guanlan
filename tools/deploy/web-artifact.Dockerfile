# Build .output on a Linux runner matching this runtime before packaging.
FROM node:24.22.1-alpine
WORKDIR /app
COPY --chown=node:node .output/ .output/
ENV NODE_ENV=production HOST=0.0.0.0 PORT=3000
USER node
EXPOSE 3000
CMD ["node", ".output/server/index.mjs"]
