# Production Task 2 Report

## Status

Implemented the resource-bounded production stack per the brief and the V0.1-dev.5 production deployment design.

- Created `.env.production.example` with empty values for every secret and required names (`APP_PORT`, `APP_KEY`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ADMIN_DISPLAY_NAME`) plus non-secret defaults (`APP_ENV=production`, `DB_HOST`, `DB_PORT`, `REDIS_HOST`, `REDIS_PORT`).
- Updated `.gitignore` to exclude `.env.production`, `tools/deploy/backup/`, `tools/deploy/*.bak`, `tools/deploy/*.tar.gz`, and `*.sql.gz`; existing entries preserved.
- Created `apps/web/Dockerfile`: two `node:22-alpine` stages; build stage runs `npm ci && npm run build`; runtime stage copies only `.output` into `/app`, sets `USER node`, `EXPOSE 3000`, and starts `node server/index.mjs`; no runtime dependency reinstall.
- Created `apps/web/.dockerignore` excluding `node_modules`, `.nuxt`, `.output`, `.npm-cache`, `tests`, caches, and `audit.json`.
- Created `compose.production.yml` with only `gateway`, `web`, `api`, `mysql`, `redis`; no Meilisearch.
- Created `docker/nginx/production.conf` (required by the gateway proxy) that routes `/api/` to `api:9501` and everything else to `web:3000` with forwarding headers.
- Created `tools/deploy/test_config.sh` that builds a temporary populated env file and asserts the rendered compose config.

## Verification

- `bash tools/deploy/test_config.sh`: `PASS: production compose config valid` (Docker 29.8.1 / Compose v5.5.1 available in the bash environment).
- `docker compose -f compose.production.yml --env-file <tmp> config`: rendered successfully; normalized port fields `host_ip: 127.0.0.1`, `published: "18080"`, `target: 80`; MySQL memory `1073741824` (1024 MB), API `805306368` (768 MB), Web `536870912` (512 MB), Redis `201326592` (192 MB), Gateway `134217728` (128 MB); CPU limits 0.8/0.75/0.5/0.2/0.1.
- `python -c "import yaml; yaml.safe_load(open('compose.production.yml'))"`: `compose YAML: OK`.
- `git diff --check`: passed (exit 0); only expected LF→CRLF advisory on `.gitignore`.
- Docker build/start was not attempted; no Docker image build success claimed.

## Command results

- `bash tools/deploy/test_config.sh` → `PASS: production compose config valid`
- `docker compose -f compose.production.yml --env-file .env.test.tmp config` → `compose config: OK`
- `git diff --check` → exit 0
- Skips: none (bash and Docker Compose were available for static config validation). Full container startup/health not run.

## Commit

`feat: add resource-bounded production stack`

## Concerns

- Full `docker compose up` build/start and live health checks were not run (runtime image build not claimed).
- The runtime stage copies `.output` contents into `/app` so `CMD ["node","server/index.mjs"]` resolves to `/app/server/index.mjs`; this matches the existing local `.output/server/index.mjs` layout but has not been exercised in a container.
- Docker build context for `apps/web` will include the local `.output`/`node_modules` only via `.dockerignore` exclusion; not build-verified.