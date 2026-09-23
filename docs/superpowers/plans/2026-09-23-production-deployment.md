# Production Deployment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deploy Guanlan as a resource-bounded, secret-driven Docker application behind BaoTa HTTPS on the specified Debian 12 server.

**Architecture:** A standalone production Compose stack runs gateway, Web, API, MySQL, and Redis on one internal network. Only `127.0.0.1:8080` is published; BaoTa Nginx owns the public domain and TLS. Build-time dependencies stay out of runtime images, and operations are exposed through checked shell scripts.

**Tech Stack:** Docker Engine and Compose v2/v5, Debian 12, BaoTa 10.0 Nginx, Node 22 Alpine, PHP 8.3/Hyperf, MySQL 8.4, Redis 7 Alpine, Bash.

## Global Constraints

- Server budget is 2 vCPU, 4 GB RAM, 100 GB disk, 8 Mbps / 15 Mbps bandwidth.
- Application services are fully containerized; BaoTa only provides domain routing, Let's Encrypt, and HTTPS termination.
- Production application port binds only to `127.0.0.1:8080` by default.
- Production secrets and `.env.production` never enter Git or backup archives.
- Meilisearch remains disabled because current search uses SQL and the server memory budget is limited.
- Configure 2 GB swap with `vm.swappiness=10` before the first image build.
- Execute this plan after `2026-09-23-dataset-integrity.md`.

---

## File Structure

- Create `.env.production.example`: complete non-secret production variable contract.
- Modify `.gitignore`: exclude `.env.production` and deployment backup scratch files.
- Create `apps/web/Dockerfile`: multi-stage production Web image.
- Create `apps/web/.dockerignore`: exclude dependencies, output, tests, and caches from build context.
- Create `compose.production.yml`: standalone resource-limited stack.
- Create `apps/api/src/Seeder/AdminCredentials.php`: environment validation for initial admin.
- Create `apps/api/tests/AdminCredentialsTest.php`: executable PHP test.
- Modify `apps/api/seeders/MistakeSeeder.php`: remove production default password.
- Modify `docker-compose.yml`: explicitly retain local-only test credentials and mark local environment.
- Create `tools/deploy/common.sh`, `deploy.sh`, `healthcheck.sh`, `backup.sh`, `restore.sh`, `update.sh`, `test_config.sh`.
- Create `docs/deployment.md`: server setup, BaoTa proxy, first deployment, update, backup, restore, and troubleshooting.
- Modify `README.md`, `docs/development-plan.md`, `CHANGELOG.md`, `VERSION`: release integration.

---

### Task 1: Production Administrator Credential Contract

**Files:**
- Create: `apps/api/tests/AdminCredentialsTest.php`
- Create: `apps/api/src/Seeder/AdminCredentials.php`
- Modify: `apps/api/seeders/MistakeSeeder.php`
- Modify: `docker-compose.yml`

**Interfaces:**
- Produces: `AdminCredentials::fromEnvironment(array $environment): array{email: string, password: string, displayName: string}`
- Local environment may use documented test credentials; production requires explicit values and at least a 12-character password.

- [ ] **Step 1: Write the failing PHP test**

Test these behaviors with real arrays:

```php
assert(AdminCredentials::fromEnvironment([
    'APP_ENV' => 'local',
])['email'] === 'admin@guanlan.local');

$production = AdminCredentials::fromEnvironment([
    'APP_ENV' => 'production',
    'ADMIN_EMAIL' => 'owner@example.com',
    'ADMIN_PASSWORD' => 'a-strong-16-char-password',
    'ADMIN_DISPLAY_NAME' => '站点管理员',
]);
assert($production['displayName'] === '站点管理员');

foreach (['missing', 'short'] as $case) {
    try {
        AdminCredentials::fromEnvironment($case === 'missing'
            ? ['APP_ENV' => 'production']
            : ['APP_ENV' => 'production', 'ADMIN_EMAIL' => 'owner@example.com', 'ADMIN_PASSWORD' => 'short']);
        throw new RuntimeException('expected credential validation failure');
    } catch (RuntimeException $exception) {
        assert(str_contains($exception->getMessage(), 'ADMIN_'));
    }
}
```

- [ ] **Step 2: Run and verify RED**

Run: `docker compose run --rm api php -d zend.assertions=1 -d assert.exception=1 tests/AdminCredentialsTest.php`

Expected: FAIL because `AdminCredentials` does not exist.

- [ ] **Step 3: Implement environment validation and Seeder use**

Normalize email with `strtolower(trim())`, require a valid email in production, require password length at least 12, and default display name to `管理员` only when omitted. In local mode preserve `admin@guanlan.local / guanlan2027` for existing smoke tests.

Replace hard-coded credentials in `MistakeSeeder::seedAdmin()` with `AdminCredentials::fromEnvironment($_ENV + $_SERVER)`. Continue to skip an existing administrator without resetting its password.

Change local Compose API environment to `APP_ENV: local` and explicitly pass the test admin values so local behavior is visible and isolated from production.

- [ ] **Step 4: Verify GREEN and existing authentication**

Run:

```bash
docker compose run --rm api php -d zend.assertions=1 -d assert.exception=1 tests/AdminCredentialsTest.php
docker compose up -d --build
bash tools/smoke.sh
```

Expected: credential test passes; smoke login returns a 64-character token and `/auth/me` returns 200.

- [ ] **Step 5: Commit**

```bash
git add apps/api/tests/AdminCredentialsTest.php apps/api/src/Seeder/AdminCredentials.php apps/api/seeders/MistakeSeeder.php docker-compose.yml
git commit -m "fix: require explicit production admin credentials"
```

---

### Task 2: Production Images and Compose Stack

**Files:**
- Create: `.env.production.example`
- Modify: `.gitignore`
- Create: `apps/web/Dockerfile`
- Create: `apps/web/.dockerignore`
- Create: `compose.production.yml`
- Create: `tools/deploy/test_config.sh`

**Interfaces:**
- Produces: `docker compose --env-file .env.production -f compose.production.yml ...`
- Produces: gateway at `127.0.0.1:${APP_PORT:-8080}`.

- [ ] **Step 1: Write the failing configuration test**

`test_config.sh` must create a temporary non-secret environment file, run Compose config, and assert:

```bash
grep -q '127.0.0.1:18080:80' "$CONFIG"
! grep -q 'change-me\|guanlan2027' "$CONFIG"
! grep -q 'meilisearch' "$CONFIG"
grep -q 'restart: unless-stopped' "$CONFIG"
grep -q 'max-size: 10m' "$CONFIG"
```

Also assert that `apps/web/Dockerfile` contains two `FROM` stages and a non-root `USER` line.

- [ ] **Step 2: Run and verify RED**

Run: `bash tools/deploy/test_config.sh`

Expected: FAIL because the production Compose and Web Dockerfile do not exist.

- [ ] **Step 3: Create production files**

Create the actual production file with commands rather than copying a password template:

```bash
umask 077
cat > .env.production <<EOF
APP_PORT=8080
APP_KEY=$(openssl rand -hex 32)
DB_DATABASE=guanlan
DB_USERNAME=guanlan
DB_PASSWORD=$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | head -c 24)
MYSQL_ROOT_PASSWORD=$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | head -c 24)
ADMIN_EMAIL=owner@example.com
ADMIN_PASSWORD=$(openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | head -c 20)
ADMIN_DISPLAY_NAME=管理员
EOF
chmod 600 .env.production
```

`.env.production.example` contains variable names, comments, and an empty value for each secret; production Compose rejects empty values with `${VAR:?message}`. It never contains a usable password.

The production Compose must:

- define only `gateway`, `web`, `api`, `mysql`, and `redis`;
- use `${VAR:?message}` for every secret;
- mount `./storage:/opt/guanlan/storage:ro` into API;
- bind gateway as `127.0.0.1:${APP_PORT:-8080}:80`;
- use `restart: unless-stopped`, healthchecks, log rotation, and the exact memory limits from the design;
- use named `mysql_data` and `redis_data` volumes;
- keep MySQL and Redis unexposed.

The Web Dockerfile builds with `npm ci && npm run build`, copies only `.output` into a clean Node runtime image, uses the `node` user, exposes 3000, and starts `node server/index.mjs`.

- [ ] **Step 4: Verify configuration and build**

Run:

```bash
bash tools/deploy/test_config.sh
docker compose --env-file /tmp/guanlan-production-test.env -f compose.production.yml config --quiet
docker compose --env-file /tmp/guanlan-production-test.env -f compose.production.yml build
```

Expected: config test exits 0 and all images build successfully.

- [ ] **Step 5: Commit**

```bash
git add .env.production.example .gitignore apps/web/Dockerfile apps/web/.dockerignore compose.production.yml tools/deploy/test_config.sh
git commit -m "feat: add resource-bounded production stack"
```

---

### Task 3: Deployment and Health Check Commands

**Files:**
- Create: `tools/deploy/common.sh`
- Create: `tools/deploy/deploy.sh`
- Create: `tools/deploy/healthcheck.sh`

**Interfaces:**
- Produces: `compose()` wrapper using the repository root, production env, and production Compose file.
- Produces: `./tools/deploy/deploy.sh [env-file]`
- Produces: `./tools/deploy/healthcheck.sh [env-file]`

- [ ] **Step 1: Write shell contract checks first**

Extend `test_config.sh` to run `bash -n` on all deployment scripts and assert `deploy.sh` contains checks for:

- `docker compose version`;
- at least 2 GB free disk;
- at least 3.5 GB total RAM;
- `.env.production` mode warning when group/other readable;
- Compose config validation before build;
- bounded health polling rather than a fixed success assumption.

Assert `healthcheck.sh` validates health 200, home 200, and unauthenticated `/api/v1/auth/me` 401.

- [ ] **Step 2: Run and verify RED**

Run: `bash tools/deploy/test_config.sh`

Expected: FAIL because deployment scripts do not exist.

- [ ] **Step 3: Implement shared helpers and commands**

`common.sh` must resolve the repository root without depending on the current directory, accept an optional env file path, and expose:

```bash
compose() {
  docker compose --env-file "$ENV_FILE" -f "$ROOT/compose.production.yml" "$@"
}
```

`deploy.sh` validates prerequisites, runs `compose config --quiet`, `compose build`, `compose up -d --remove-orphans`, then calls the health check with up to 30 attempts and 2-second intervals.

`healthcheck.sh` reads `APP_PORT`, calls only `http://127.0.0.1:$APP_PORT`, checks Compose service health, and exits nonzero with recent logs on failure.

- [ ] **Step 4: Verify on the WSL production stack**

Run:

```bash
bash tools/deploy/test_config.sh
cp .env.production.example /tmp/guanlan-production.env
# Populate every empty secret using the same openssl commands before invoking Compose.
./tools/deploy/deploy.sh /tmp/guanlan-production.env
./tools/deploy/healthcheck.sh /tmp/guanlan-production.env
ss -ltn | grep '127.0.0.1:8080'
! ss -ltn | grep '0.0.0.0:8080'
```

Expected: deploy and health checks exit 0; port 8080 is loopback-only.

- [ ] **Step 5: Commit**

```bash
git add tools/deploy/common.sh tools/deploy/deploy.sh tools/deploy/healthcheck.sh tools/deploy/test_config.sh
git commit -m "feat: add checked production deployment command"
```

---

### Task 4: Backup, Restore, and Update Rollback

**Files:**
- Create: `tools/deploy/backup.sh`
- Create: `tools/deploy/restore.sh`
- Create: `tools/deploy/update.sh`
- Modify: `tools/deploy/test_config.sh`

**Interfaces:**
- Produces: `backup.sh [env-file] [backup-dir]`, printing the created `.tar.gz` path.
- Produces: `restore.sh <archive> [env-file]`.
- Produces: `update.sh [env-file]`, returning the old Git SHA on failed health checks.

- [ ] **Step 1: Add failing shell contracts**

Assert all scripts pass `bash -n`. Assert:

- backup uses `mysqldump --single-transaction --routines --triggers`;
- archive includes `database.sql`, `VERSION`, `git-sha.txt`, and `dataset-manifest.json`;
- archive excludes `.env.production`;
- restore requires an archive argument and runs a safety backup first;
- update records `git rev-parse HEAD`, backs up before `git pull --ff-only origin main`, and restores the old SHA if health checks fail.

- [ ] **Step 2: Run and verify RED**

Run: `bash tools/deploy/test_config.sh`

Expected: FAIL because backup, restore, and update scripts do not exist.

- [ ] **Step 3: Implement operations scripts**

Use `/www/backup/guanlan` as the default backup directory, `umask 077`, temporary directories cleaned by `trap`, SHA-256 checksum files, and retention of the newest 7 archives.

Restore must validate the archive checksum and required members before touching MySQL. It must pipe `database.sql` into the running MySQL container only after a safety backup succeeds.

Update must refuse a dirty Git tree, create a backup, pull `main` with fast-forward only, rebuild, start, and health-check. On failure it checks out the recorded SHA, rebuilds the previous images, and reports both the failed and restored SHAs.

- [ ] **Step 4: Run backup and isolated restore rehearsal**

Run on the WSL production stack:

```bash
archive=$(./tools/deploy/backup.sh /tmp/guanlan-production.env /tmp/guanlan-backups)
test -f "$archive"
tar -tzf "$archive" | sort
./tools/deploy/restore.sh "$archive" /tmp/guanlan-production.env
./tools/deploy/healthcheck.sh /tmp/guanlan-production.env
```

Expected: archive has all required members and no env file; restore and health check exit 0.

- [ ] **Step 5: Commit**

```bash
git add tools/deploy/backup.sh tools/deploy/restore.sh tools/deploy/update.sh tools/deploy/test_config.sh
git commit -m "feat: add production backup and rollback tooling"
```

---

### Task 5: BaoTa Deployment Guide

**Files:**
- Create: `docs/deployment.md`
- Modify: `README.md`
- Modify: `docs/development-plan.md`

**Interfaces:**
- Produces: exact server preparation and BaoTa reverse proxy procedure for `/www/wwwroot/guanlan`.

- [ ] **Step 1: Write the deployment guide**

Cover these exact sections:

1. Server baseline and 2 GB swap commands.
2. Clone `https://github.com/wenckerwan/guanlan.git` into `/www/wwwroot/guanlan`.
3. Generate `.env.production` values with `openssl rand -hex 32` and set mode 600.
4. Run `./tools/deploy/deploy.sh`.
5. Create a BaoTa site, request Let's Encrypt, enable force HTTPS, and proxy to `http://127.0.0.1:8080`.
6. Preserve forwarding headers and set proxy timeouts to 60 seconds.
7. Allow only 22, 80, and 443 in the security group/firewall.
8. Configure nightly `backup.sh` in BaoTa scheduled tasks and retain 7 archives.
9. Run update, restore, logs, health checks, and rollback.
10. Troubleshoot low memory, failed data integrity, unhealthy containers, and port conflicts.

State explicitly that BaoTa's MySQL, PHP, and Redis are not used by Guanlan.

- [ ] **Step 2: Update project standards**

Add deployment guide links to README. Add a V0.1-dev.5 production deployment item to `docs/development-plan.md` with the specified server baseline and concrete completion criteria.

- [ ] **Step 3: Verify documentation**

Run: `python tools/doclink.py`

Expected: all relative Markdown links pass.

- [ ] **Step 4: Commit**

```bash
git add docs/deployment.md README.md docs/development-plan.md
git commit -m "docs: add BaoTa production deployment guide"
```

---

### Task 6: V0.1-dev.5 Integrated Verification and Release State

**Files:**
- Modify: `VERSION`
- Modify: `apps/api/src/Controller/HealthController.php`
- Modify: `apps/web/components/SiteHeader.vue`
- Modify: `README.md`
- Modify: `docs/development-plan.md`
- Modify: `CHANGELOG.md`

**Interfaces:**
- Produces: one consistent `V0.1-dev.5` version across application and documentation.

- [ ] **Step 1: Set the release version**

Change all current-version surfaces from `V0.1-dev.4` to `V0.1-dev.5`. Do not rewrite historical CHANGELOG entries.

- [ ] **Step 2: Run the complete local verification suite**

Run:

```powershell
git diff --check
python tools/doclink.py
python tools/phpcheck.py
python tools/phpcheck_selftest.py
python -m unittest discover -s tools/ingest -p "test_*.py" -v
npm test --prefix apps/web
npm run build --prefix apps/web
```

Expected: all commands exit 0; frontend reports 36 or more passing tests.

- [ ] **Step 3: Run complete Docker verification**

In a WSL-native copy:

```bash
docker compose up --build -d
bash tools/lint.sh
bash tools/smoke.sh
./tools/deploy/deploy.sh /tmp/guanlan-production.env
./tools/deploy/healthcheck.sh /tmp/guanlan-production.env
bash tools/deploy/test_config.sh
```

Then run the dataset negative test and backup/restore rehearsal from the earlier tasks.

Expected: normal APIs return 200/201, expected auth/validation paths return 401/409/422, dataset mutation exits nonzero before migration, production port is loopback-only, and restored data passes health checks.

- [ ] **Step 4: Inspect resource and logging controls**

Run:

```bash
docker inspect guanlan-api-1 --format '{{.HostConfig.Memory}} {{.HostConfig.NanoCpus}} {{json .HostConfig.LogConfig}}'
docker inspect guanlan-mysql-1 --format '{{.HostConfig.Memory}} {{.HostConfig.NanoCpus}} {{json .HostConfig.LogConfig}}'
docker compose --env-file /tmp/guanlan-production.env -f compose.production.yml ps
```

Expected: memory and CPU values match the design; `json-file` options are `10m` and `3`; every service is running or healthy.

- [ ] **Step 5: Update factual release records**

Paste actual counts, image sizes, health responses, negative integrity output, backup archive members, restore result, and resource inspection values into CHANGELOG. Mark development-plan item 1.4 and production deployment configuration complete only when all evidence exists.

- [ ] **Step 6: Security review and commit**

Run:

```powershell
git status --ignored --short
rg -n --hidden -g '!apps/web/node_modules/**' -g '!apps/web/.nuxt/**' -g '!apps/web/.output/**' -g '!.git/**' '(gh[pousr]_[A-Za-z0-9_]{20,}|BEGIN (RSA |OPENSSH |EC )?PRIVATE KEY|ADMIN_PASSWORD\s*=\s*[^g]|DB_PASSWORD\s*=\s*[^g])' .
git diff --check
```

Confirm `.env.production`, backups, private keys, tokens, F-drive originals, dependencies, and build output are not staged.

Commit:

```bash
git add VERSION apps/api/src/Controller/HealthController.php apps/web/components/SiteHeader.vue README.md docs/development-plan.md CHANGELOG.md
git commit -m "release: prepare V0.1-dev.5 production deployment"
```
