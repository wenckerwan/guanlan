# V0.1-dev.2 内容 API 实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** 为观澜补齐可启动的 Hyperf 后端骨架，把六大学科/章节/知识点、首页热点和资料卡从前端硬编码迁入 MySQL，并提供统一的 `/api/v1` 内容接口，让首页、资料库页、学科详情页在 SSR 下从真实数据库取数。

**Architecture:** 后端以 Hyperf 3.1（Swoole）+ MySQL 8.4 承载内容；用 `hyperf/db-connection` 迁移建 5 张表（subjects/chapters/knowledge_points/hotspots/documents），Seeder 把 `data/*.ts` 转录入库并保持幂等；Model→Service→Resource→Controller 分层，所有端点统一 `{"data": ...}` 包络、字段 camelCase 与前端 TS 类型 1:1。前端新增 `composables/useApi.ts`（SSR 走容器 DNS `http://api:9501/api/v1`、客户端走 nginx 反代 `/api/v1`），三页面改用 `useApiFetch` 并复用既有纯函数（sortHotspots/buildHomeSummary/getSubjectBySlug/validateSubjects），删除 `data/home.ts` 与 `data/subjects.ts`。消除 `HealthController::home()` 与前端的双份热点硬编码。

**Tech Stack:** PHP 8.2+ / Hyperf 3.1 / Swoole、MySQL 8.4、Nuxt 3.21.11 / Vue 3.5 / TypeScript、Docker Compose（WSL2 内运行）、Node test runner。

## 全局约束

- 分支 `feature/content-api`；提交前缀 `feat: ... for V0.1-dev.2`；文档版本串 `V0.1-dev.2`，git tag 小写 `v0.1-dev.2`。
- 统一 API 包络 `{ "data": ... }`；对外字段 camelCase，与前端 TS 类型 1:1；章节对外 id 用 `chapters.chapter_key`。
- 迁移用 Hyperf `migrate`（版本化、幂等），不用 `schema.sql`；全库 InnoDB / utf8mb4 / utf8mb4_unicode_ci。
- compose 运行必须在 WSL2 原生副本 `~/guanlan`（tar 复制排除 node_modules/.nuxt/.output/vendor/.git），禁止直接挂 `/mnt/d`。
- 不提交 `vendor/`、`node_modules`、`.nuxt`、`.output`、`runtime/`、原始资料或密钥。
- CHANGELOG「验证」段必须粘贴实际执行的命令与结果。
- Out of scope（顺延另开任务）：manifest→MySQL 导入器、Meilisearch 索引（dev.3）、vue peer 依赖升级、compose profiles。

### 任务 1：建分支 + spec/plan 文档（治理先行）

**文件：**
- 新建：`docs/superpowers/specs/2026-09-19-content-api-design.md`
- 新建：`docs/superpowers/plans/2026-09-19-content-api.md`

- [ ] `git checkout -b feature/content-api`。
- [ ] spec：H1 `# V0.1-dev.2 内容 API 设计`；H2 依次 目标/范围/架构/交互规则/数据内容/验证；「数据内容」写入五表结构与 JSON 契约；「范围」写明导入器/Meilisearch/vue peer/compose profiles 顺延。
- [ ] plan：本文件。

### 任务 2：修复 Hyperf 骨架（先让它能启动）

**文件：**
- 修改：`apps/api/composer.json`
- 修改：`apps/api/bin/hyperf.php`
- 修改：`apps/api/Dockerfile`
- 修改：`.env.example`（根）
- 新建：`apps/api/config/config.php`、`apps/api/config/container.php`
- 新建：`apps/api/config/autoload/server.php`、`databases.php`、`exceptions.php`、`logger.php`、`dependencies.php`
- 新建：`apps/api/.env`、`apps/api/.gitignore`
- 新建：`apps/api/src/Exception/Handler/AppExceptionHandler.php`

- [ ] composer.json 追加（均 `^3.1`，engine `^2.10`）：`hyperf/config`、`hyperf/database`、`hyperf/db-connection`、`hyperf/engine`、`hyperf/logger`、`hyperf/memory`。不引入 redis/cache/guzzle。
- [ ] `config.php`：app_name/app_env 读 env、`scan_cache_path = BASE_PATH.'/runtime/scan.cache'`。
- [ ] `container.php`：`new Container((new DefinitionSourceFactory())())` + `ApplicationContext::setContainer`。
- [ ] `autoload/server.php`：单 http server `0.0.0.0:9501`，`ON_REQUEST => HttpServer::onRequest`。
- [ ] `autoload/databases.php`：default=mysql，host/port/database/username/password 读 `DB_*` env，charset/collation `utf8mb4/utf8mb4_unicode_ci`，pool min1/max10，`commands.migrations.path = BASE_PATH.'/migrations'`。
- [ ] `autoload/exceptions.php`：`handler.http = [HttpExceptionHandler, AppExceptionHandler]`。
- [ ] `autoload/logger.php`：default → StdoutLogger；`dependencies.php`：空数组。
- [ ] `bin/hyperf.php` 换标准入口（BASE_PATH → autoload → config/container.php → `ApplicationInterface->run()`）；`AppExceptionHandler` 按标准骨架（记日志 + JSON 500）。
- [ ] `apps/api/.env`（提交）：`APP_NAME=guanlan-api`、`APP_ENV=production`、`DB_HOST=mysql`、`DB_PORT=3306`、`DB_DATABASE=guanlan`、`DB_USERNAME=guanlan`、`DB_PASSWORD=change-me`、`REDIS_HOST=redis`、`SEARCH_HOST=http://meilisearch:7700`；根 `.env.example` 同步并把 `MEILI_HOST` 改名 `SEARCH_HOST`。
- [ ] `apps/api/.gitignore`：`/vendor/`、`/runtime/*`（保留 `!.gitignore`）。
- [ ] Dockerfile：`composer install` 前加 `RUN composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/`；`COPY composer.json composer.lock ./`；`COPY . .` 后 `RUN mkdir -p runtime`。
- [ ] 在 WSL2 副本生成并提交 `composer.lock`；验证镜像可 build、`php bin/hyperf.php start` 启动、`/api/v1/health` 返回 200（此时不查库）。

### 任务 3：Schema（5 表，Hyperf 迁移）

**文件：**
- 新建：`apps/api/migrations/2026_09_19_000001_create_subjects_table.php`
- 新建：`apps/api/migrations/2026_09_19_000002_create_chapters_table.php`
- 新建：`apps/api/migrations/2026_09_19_000003_create_knowledge_points_table.php`
- 新建：`apps/api/migrations/2026_09_19_000004_create_hotspots_table.php`
- 新建：`apps/api/migrations/2026_09_19_000005_create_documents_table.php`

- [ ] `subjects`：id PK；slug VARCHAR(64) UNIQUE；name VARCHAR(128)；short VARCHAR(32)；tone VARCHAR(32) default 'jade'；detail VARCHAR(255) default ''；intro TEXT；asset_count INT default 0；sort_order INT default 0。
- [ ] `chapters`：id PK；subject_id FK→subjects.id CASCADE；chapter_key VARCHAR(64)；title VARCHAR(191)；summary VARCHAR(512) default ''；sort_order INT；UNIQUE(subject_id, chapter_key)。
- [ ] `knowledge_points`：id PK；chapter_id FK→chapters.id CASCADE；title VARCHAR(191)；summary VARCHAR(512) default ''；category VARCHAR(64) NULL；year SMALLINT NULL；source VARCHAR(191) NULL；sort_order INT；KEY(chapter_id)、KEY(category)、KEY(year)。
- [ ] `hotspots`：id PK；title VARCHAR(191) UNIQUE；level VARCHAR(2) default 'A'；summary VARCHAR(512) default ''；type VARCHAR(64) default ''；tag VARCHAR(64) default ''；subject_id FK→subjects.id CASCADE；chapter_id FK→chapters.id SET NULL；updated_at DATETIME NULL；KEY(subject_id)、KEY(level, updated_at)。
- [ ] `documents`：id PK；title VARCHAR(191)；meta VARCHAR(191) default ''；progress TINYINT default 0；cover VARCHAR(32) default ''；tone VARCHAR(32) default 'jade'；category VARCHAR(64) default ''；year SMALLINT NULL；source VARCHAR(191) NULL；file_path VARCHAR(512) NULL UNIQUE；subject_id FK→subjects.id SET NULL；sort_order INT；KEY(subject_id)、KEY(category)、KEY(year)。
- [ ] 迁移用 `Hyperf\Database\Migrations\Migration` + `Schema::create`，外键 `foreignId(...)->constrained()->cascadeOnDelete()` 风格；顺序 subjects→chapters→knowledge_points→hotspots→documents；每表含 `created_at`/`updated_at` DATETIME NULL。
- [ ] 容器内 `php bin/hyperf.php migrate --force` 成功，`SHOW TABLES` 出 5 表 + migrations 表。

### 任务 4：Model + Seeder（把 data/*.ts 内容搬进 MySQL）

**文件：**
- 新建：`apps/api/src/Model/Subject.php`、`Chapter.php`、`KnowledgePoint.php`、`Hotspot.php`、`Document.php`
- 新建：`apps/api/seeders/SubjectSeeder.php`、`HotspotSeeder.php`、`DocumentSeeder.php`

- [ ] Model 继承 `Hyperf\Database\Model\Model`，`$guarded = []`；关系：Subject hasMany Chapter、Subject hasMany Hotspot、Chapter hasMany KnowledgePoint、Hotspot belongsTo Subject/Chapter、Document belongsTo Subject。
- [ ] `SubjectSeeder`：以 `apps/web/data/subjects.ts` 为唯一数据源转录 6 学科（slug/name/short/tone/detail/intro，sort_order=数组序，asset_count 取 home.ts 摘要 3/2/3/6/3/7），每学科 2 章（chapter_key=TS `chapters[].id`）、每章 3 知识点。幂等：firstOrCreate（subjects 按 slug、chapters 按 subject_id+chapter_key、points 按 chapter_id+title）。
- [ ] `HotspotSeeder`：转录 `data/home.ts` 6 条热点；subject_id 按 subjectSlug 查、chapter_id 按 (subject_id, chapterId) 查；用 `DB::table('hotspots')` 查询构造器插入并显式写 created_at/updated_at 为原日期（2026-09-06…2026-09-01）；按 title firstOrCreate。
- [ ] `DocumentSeeder`：转录 recentDocuments 2 条（title/meta/progress/cover/tone，sort_order 0/1）；category/year/source/file_path/subject_id 留空。
- [ ] 容器内 `php bin/hyperf.php db:seed --force`；计数 subjects 6 / chapters 12 / knowledge_points 36 / hotspots 6 / documents 2；重复执行计数不变。

### 任务 5：Service / Resource / Controller / 路由

**文件：**
- 新建：`apps/api/src/Service/SubjectService.php`、`HomeService.php`
- 新建：`apps/api/src/Resource/SubjectResource.php`、`ChapterResource.php`、`KnowledgePointResource.php`、`HotspotResource.php`、`DocumentResource.php`、`SubjectSummaryResource.php`
- 新建：`apps/api/src/Controller/SubjectController.php`、`HomeController.php`
- 修改：`apps/api/src/Controller/HealthController.php`
- 修改：`apps/api/config/routes.php`

- [ ] `SubjectService::list()`：`Subject::with('chapters.knowledgePoints','hotspots')` 按 sort_order 排序（章节/知识点同）；`findBySlug(string): ?Subject`。`HomeService::index()`：hotspots（`with('subject','chapter')`，`ORDER BY FIELD(level,'S','A','B','C'), updated_at DESC`）、documents（sort_order）、subjects 摘要（`withCount('chapters')`，sort_order）。
- [ ] Resource 输出契约（camelCase，与前端类型 1:1）：`SubjectResource` → `{ slug, name, short, tone, detail, intro, hotspots: string[], chapters: [{ id: chapter_key, title, summary, points: [{ title, summary }] }] }`；`HotspotResource` → `{ level, title, summary, type, updatedAt: 'Y-m-d', tag, subjectSlug, chapterId: chapter_key }`；`DocumentResource` → `{ title, meta, progress:int, cover, tone }`；`SubjectSummaryResource` → `{ slug, name, short, tone, detail, count: asset_count }`。
- [ ] 路由（GET，包络 `{"data":...}`；nginx 保留 `/api/` 前缀）：`/api/v1/health` → `{data:{status:'ok', db:'ok'|'fail'(try/catch `Db::select('select 1')`), version:'V0.1-dev.2'}}`；`/api/v1/home` → `{data:{hotspots[], documents[], subjects[]摘要}}`；`/api/v1/subjects` → `{data:SubjectDTO[]}`；`/api/v1/subjects/{slug}` → `{data:SubjectDTO}`，未知 slug 返回 404 `{message:'subject not found'}`。
- [ ] 删除 `HealthController::home()` 硬编码热点，`/api/v1/home` 改指 `HomeController::index`（消除双份硬编码）。
- [ ] 容器内 curl 四端点，逐字段对齐契约后再进任务 6。

### 任务 6：compose DB_* + 启动链路

**文件：**
- 修改：`docker-compose.yml`（仅 api service environment）
- 修改：`apps/api/Dockerfile`（仅 CMD）

- [ ] api env 追加：`DB_PORT: 3306`、`DB_DATABASE: guanlan`、`DB_USERNAME: guanlan`、`DB_PASSWORD: change-me`（与 mysql service 一致）。不动其他 service。
- [ ] Dockerfile CMD 改为等待-迁移-种子-启动链：`CMD ["sh","-c","until php bin/hyperf.php migrate --force; do echo 'waiting for mysql...'; sleep 3; done && php bin/hyperf.php db:seed --force && php bin/hyperf.php start"]`。
- [ ] WSL2 副本 `~/guanlan` 执行 `docker compose up --build -d`；`docker compose logs api` 无 fatal；宿主机 curl 四端点全 200 且含真实 DB 数据。

### 任务 7：前端 API 层 + 新测试（测试先行）

**文件：**
- 新建：`apps/web/tests/api.test.mjs`
- 新建：`apps/web/utils/api.mjs`
- 新建：`apps/web/composables/useApi.ts`
- 新建：`apps/web/types/api.ts`
- 修改：`apps/web/nuxt.config.ts`

- [ ] 先写 `tests/api.test.mjs`（node:test + node:assert/strict，只 import `utils/api.mjs`、`utils/home.mjs`、`utils/subjects.mjs` + 内联 envelope fixture）：断言 `unwrapEnvelope({data:X})===X`、`unwrapEnvelope(null)` 返回安全默认、对 envelope fixture `validateSubjects(unwrapEnvelope(...)).valid===true`、`buildHomeSummary` 输出 S 级在前且 hotspots≤4/subjects≤6。先跑确认失败。
- [ ] 实现 `utils/api.mjs`：`unwrapEnvelope(payload, fallback)`、`toHomeSummary(payload)`（内部组合 `buildHomeSummary`）。再跑确认通过。
- [ ] `types/api.ts`：把 `data/subjects.ts` 的 `KnowledgePoint/SubjectChapter/Subject` 原样迁入，新增 `Hotspot`、`DocumentCard`、`SubjectSummary`、`HomePayload`、`ApiEnvelope<T>`。
- [ ] `nuxt.config.ts` runtimeConfig 增私有键 `apiInternalBase: process.env.NUXT_API_INTERNAL_BASE || 'http://api:9501/api/v1'`；`public.apiBase` 不变。
- [ ] `composables/useApi.ts`：`useApiFetch<T>(path)` → `useFetch<ApiEnvelope<T>>(path, { baseURL: import.meta.server ? config.apiInternalBase : config.public.apiBase, transform: unwrapEnvelope, default: () => fallback })`。SSR 关键：服务端必须走容器 DNS `http://api:9501/api/v1`（相对 `/api/v1` 在 Nitro 内无 origin）；客户端走 `/api/v1` 由 nginx 反代；API 宕机渲染空态而非 500。

### 任务 8：三页面切 API + 删除硬编码数据

**文件：**
- 修改：`apps/web/pages/index.vue`
- 修改：`apps/web/pages/subjects/index.vue`
- 修改：`apps/web/pages/subjects/[slug].vue`
- 删除：`apps/web/data/home.ts`
- 删除：`apps/web/data/subjects.ts`

- [ ] `index.vue`：删两个静态 import，改 `const { data: home } = await useApiFetch<HomePayload>('/home')`；热点区对 `home.hotspots` 先 `sortHotspots`（复用 utils/home.mjs）再按关键词过滤 slice(0,4)，默认视图用 `buildHomeSummary`；学科网格迭代 subjects 摘要（“X章”用 count）；继续阅读迭代 `home.documents`（字段名不变，模板零改动）；加空态文案；类型从 `~/types/api` 导入。
- [ ] `subjects/index.vue`：`await useApiFetch<Subject[]>('/subjects')`；对结果 `validateSubjects`（复用 utils/subjects.mjs），invalid 时 console.error + 空态；卡片逻辑不变。
- [ ] `subjects/[slug].vue`：`await useApiFetch<Subject[]>('/subjects')` 后 `getSubjectBySlug(...)`（复用 util）；保留 `?chapter=` 高亮与未知 slug 空态。
- [ ] `grep -r "data/home\|data/subjects" apps/web` 确认无残余后删除两数据文件（内容已在任务 4 转录进 Seeder，git 历史可查）。
- [ ] 回归：`npm test`（subjects/home/api 三文件全绿）、`npm run build` 通过。

### 任务 9：版本记录、README、回归与 tag（收尾）

**文件：**
- 修改：`VERSION`
- 修改：`CHANGELOG.md`
- 修改：`README.md`
- 修改：`apps/web/components/SiteHeader.vue`

- [ ] `VERSION` → `V0.1-dev.2`；SiteHeader 抽屉文案 `V0.1-dev.1` → `V0.1-dev.2`。
- [ ] README 增补「本机 Docker 工作流（WSL2，无 Docker Desktop）」：Win10 LTSC 19044 装不了 Docker Desktop；一律在 WSL2 Ubuntu-24.04 内操作；compose 必须从 WSL 原生副本运行（tar 复制排除 node_modules/.nuxt/.output/vendor/.git 到 ~/guanlan），禁止直接挂 /mnt/d（9P 慢 + web 容器 npm ci 会用 Linux 二进制覆盖 Windows node_modules）；已配 registry mirror + Aliyun composer mirror。
- [ ] CHANGELOG 新增 `V0.1-dev.2` 节（新增/修复/变更/验证/已知限制）。修复：Hyperf 骨架缺失无法启动、compose api 缺 DB_*、热点双份硬编码。变更：根 .env.example MEILI_HOST→SEARCH_HOST。已知限制：documents 的 category/year/source/file_path 本期无数据（导入器 deferred）、Meilisearch 为 dev.3、vue peer 警告仍在（单独任务）、hotspots.title 有 UNIQUE 约束、时政显示名以 subjects 表为准。验证：粘贴 Verification 实际执行结果。
- [ ] 回归四件套：`npm test`、`npm run build`、`git diff --check`、`git status`（无 vendor/runtime/node_modules/原始资料泄漏）。
- [ ] 提交（`feat: ... for V0.1-dev.2`）+ tag `v0.1-dev.2`。

## 验证（收尾前完整执行）

A. 容器链路（WSL2 内副本）：

```bash
wsl -d Ubuntu-24.04
cd ~ && rm -rf guanlan && mkdir guanlan
tar -cf - -C "/mnt/d/code_files/观澜｜考研政治知识库/guanlan" \
  --exclude=node_modules --exclude=.nuxt --exclude=.output --exclude=vendor --exclude=.git . \
  | tar -xf - -C ~/guanlan
cd ~/guanlan && docker compose up --build -d
docker compose logs api --tail 50     # migrate/db:seed 成功、监听 9501、无 Fatal
curl http://localhost:8080/api/v1/health
curl http://localhost:8080/api/v1/subjects | head -c 400
curl http://localhost:8080/api/v1/subjects/marxism | head -c 400
curl http://localhost:8080/api/v1/home | head -c 600
curl -o /dev/null -w '%{http_code}\n' http://localhost:8080/api/v1/subjects/not-exist  # 404
curl -o /dev/null -w '%{http_code}\n' http://localhost:8080/                            # 200
```

期望：health `db:"ok"`；subjects 6 学科（marxism 含 materialism/epistemology 两章各 3 points）；home 6 热点（S 在前、updatedAt 原日期）+ 2 文档卡 + 6 摘要（count 3/2/3/6/3/7）；首页 HTML 内出现热点标题（证明 SSR 经 `http://api:9501` 取数，非客户端兜底）。

B. 前端与仓库（Windows git-bash）：

```bash
cd "D:\code_files\观澜｜考研政治知识库\guanlan\apps\web" && npm test   # 3 文件全绿
npm run build                                                          # 构建成功（vue peer 警告可容忍）
cd ../.. && git diff --check && git status                             # 无输出/无泄漏
```
