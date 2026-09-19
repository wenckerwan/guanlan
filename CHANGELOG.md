# 更新记录

## V0.1-dev.2 - 2026-09-19

### 新增

- 新增五张内容表迁移：`subjects`/`chapters`/`knowledge_points`/`hotspots`/`documents`，`knowledge_points` 与 `documents` 预留 `category`/`year`/`source` 字段。
- 新增 Hyperf 查询 API 四端点：`/api/v1/health`（含 DB 连通性）、`/api/v1/home`、`/api/v1/subjects`、`/api/v1/subjects/{slug}`，统一 `{"data": ...}` 包络、camelCase 字段。
- 新增 Model / Service / Resource / Controller 分层，Resource 精确控制对外契约（章节以 `chapter_key` 作对外 id、热点 `updatedAt` 取 `updated_at`）。
- 新增 Seeder 把前端静态 `data/*.ts` 内容灌入 MySQL，全部 `firstOrCreate` 幂等。
- 新增前端 API 层：`utils/api.mjs`（`unwrapEnvelope`/`toHomeSummary`）、`composables/useApi.ts`（SSR 走容器 DNS、客户端走 nginx 反代）、`types/api.ts`、`tests/api.test.mjs`（6 例）。
- 首页、学科总览页、学科详情页改用 SSR `useApiFetch` 取数，保留 `?chapter=` 高亮与空态；新增 `apps/api/config/autoload/commands.php` 注册数据库控制台命令；新增 `apps/api/.env.example`。

### 修复

- 修复 Hyperf 骨架缺失导致无法启动：补齐 `config/config.php`、`config/container.php`、`config/autoload/{server,databases,exceptions,logger,dependencies}.php` 与标准 `bin/hyperf.php` 入口，并修正 config 文件里 `env()` 未导入命名空间（须 `use function Hyperf\Support\env;`）。
- 修复 `migrate`/`db:seed` 命令未注册：本地 Composer 镜像的 `hyperf/database` dist 缺少 `ConfigProvider`，命令未并入 `config('commands')`；改由 `commands.php` 返回 `CommandCollector::getAllCommands()` 补齐。
- 修复 Seeder 类名解析失败：Hyperf 以全局命名空间按文件名解析 seeder（`new Str::studly(basename)()`），故移除 seeders 的 `App\Seeders` 命名空间，并删除会重复执行的 `DatabaseSeeder`，改由 `HotspotSeeder` 的「subjects 为空则先跑 SubjectSeeder」保证顺序。
- 修复热点双份硬编码：删除后端 `HealthController::home()` 硬编码与前端 `data/home.ts`、`data/subjects.ts`，改由数据库单一来源。
- 修复 `docker-compose.yml` 的 `api` 服务缺少 `DB_*` 账号密码导致连不上 mysql。
- 修复 `apps/web` 在容器内 `npm ci` 失败（commander/cac/@nuxt/schema 版本漂移使 lock 与 package.json 在 npm 10 下不同步）：用容器同版本 npm 重新生成 `package-lock.json`，并新增 `apps/web/.npmrc`（`legacy-peer-deps=true`）对齐 peer 解析。

### 变更

- 版本从 `V0.1-dev.1` 更新为 `V0.1-dev.2`，同步 `VERSION`、README 与 SiteHeader 抽屉文案。
- 根 `.env.example` 的 `MEILI_HOST` 统一改名为 `SEARCH_HOST`，与 compose 运行时对齐。
- `apps/api/Dockerfile` 增加 Aliyun Debian/Composer 镜像、`libbrotli-dev`/`libssl-dev` 依赖、`platform.php=8.3.33`，CMD 改为「等待 mysql → migrate → db:seed → start」链。
- README 增补「本机 Docker 工作流（WSL2，无 Docker Desktop）」。

### 验证

- 容器链路（WSL2 Ubuntu-24.04 原生副本 `~/guanlan`，`docker compose up --build -d`）：
  - `GET /api/v1/health` → `{"data":{"status":"ok","db":"ok","version":"V0.1-dev.2"}}`
  - `SHOW TABLES` → subjects / chapters / knowledge_points / hotspots / documents + migrations
  - 行数：subjects=6 / chapters=12 / knowledge_points=36 / hotspots=6 / documents=2
  - `GET /api/v1/home` → 热点等级序 S·S·S·A·A·A，`updatedAt` 2026-09-06…09-01，学科摘要 count 3/2/3/6/3/7
  - `GET /api/v1/subjects` → 200；`GET /api/v1/subjects/marxism` → 章节 `materialism`/`epistemology` 含 points；`GET /api/v1/subjects/not-exist` → 404
  - 首页 SSR HTML 命中「十五五规划与开局之年」「马克思主义基本原理」，证明服务端经 `http://api:9501` 取数而非客户端兜底
  - 重复 `php bin/hyperf.php db:seed --force` 后行数不变（6/12/36/6/2），退出码 0
- `npm test`（`apps/web`）：11/11 通过（home / subjects / api 三文件）。
- `npm run build`（`apps/web`）：通过，Nuxt 3.21.11 生产构建成功。
- `git diff --check`：通过（无空白错误）。

### 已知限制

- `documents` 的 `category`/`year`/`source`/`file_path`/`subject_id` 本期无数据，manifest→MySQL 导入器顺延到后续版本。
- Meilisearch 接入为后续版本；搜索、用户中心、收藏、阅读记录仍显示「即将开放」。
- 时政热点对外显示名以 `subjects.name`（当代世界经济与政治）为准，不再保留旧 `home.ts` 的「形势与政策」别名。
- `hotspots.title` 具 UNIQUE 约束，重名热点会被 `firstOrCreate` 合并。
- `apps/web` 的 peer 依赖冲突（`@unhead/vue` 需 `vue>=3.5.18`，root 固定 `vue@3.5.12`）以 `.npmrc` 的 `legacy-peer-deps` 绕过，未真正升级依赖，根治需单独任务。
- `apps/api/.env` 被根 `.gitignore` 忽略、不入库，运行期变量由 `docker-compose.yml` 注入；`.env.example` 仅用于脱离容器的本地开发。
- 本版本验证在 WSL2 原生副本内完成（宿主机 Windows 10 LTSC 19044 无法运行 Docker Desktop）。

## V0.1-dev.1 - 2026-09-07

### 新增

- 新增 `/subjects` 六大学科资料库总览页。
- 新增 `/subjects/:slug` 学科详情页，包含面包屑、返回入口、章节卡片和知识点列表。
- 新增共享桌面导航、移动端抽屉菜单和底部资料导航。
- 首页学科卡片和热点推荐改为真实路由跳转，并保留章节 query 参数。
- 新增前端静态学科数据模型和 slug 查询测试，为 V0.1-dev.2 API 接入预留字段。
- 将完整版本路线写入 `docs/release-roadmap.md`，并记录本版本设计与实施计划。

### 修复

- 修复首页“进入资料库”和学科卡片使用 `href="#"` 死链接的问题。

### 变更

- 版本从 `V0.1` 更新为 `V0.1-dev.1`。
- 本阶段明确限定为纯前端静态数据，不新增 Hyperf API 或 MySQL 逻辑。

### 验证

- `npm test`（`apps/web`）：5/5 通过。
- `npm run build`（`apps/web`）：通过，Nuxt 3.21.11 生产构建成功。
- `git diff --check`：通过。

### 已知限制

- 学科、章节和知识点仍为前端静态种子数据，尚未接入 Hyperf/MySQL。
- 搜索、用户中心、收藏和阅读记录仍显示为即将开放。
- 构建过程存在 Nuxt/Vue 依赖的 Node `DEP0155` trailing slash 弃用警告，不影响构建结果。

## V0.1 - 2026-09-07

### 新增

- Nuxt 3 响应式首页，包含观澜品牌、继续阅读、六大学科入口和最新时政热点推荐。
- Hyperf `/api/v1/health` 与 `/api/v1/home` API 骨架。
- 本地资料清单工具，按 SHA-256 去重并生成导入审计清单。
- Docker Compose 编排 Nuxt、Hyperf、MySQL、Redis、Meilisearch 与 Nginx 的基础结构。

### 修复

- 初始版本暂无已记录的错误修复。

### 变更

- 建立 `main` 稳定分支及 `feature/<topic>`、`fix/<topic>`、`docs/<topic>` 分支约定。
- 建立版本文件、提交前缀、同步清单和 CHANGELOG 固定格式。

### 验证

- `node --test apps/web/tests/home.test.mjs`：2/2 通过。
- `python -m unittest tools/ingest/test_manifest.py`：2/2 通过。
- `npm run build`（`apps/web`）：通过，Nuxt 3.21.11 构建成功。
- `npm audit --omit=dev --audit-level=high --json`：high/critical 漏洞为 0。
- 本地预览 HTTP 返回 200，页面包含观澜品牌和热点推荐内容。

### 已知限制

- Hyperf 业务数据库、用户认证和邀请码系统尚未实现。
- 真实 Meilisearch 搜索接入尚未实现。
- PDF 阅读器、OCR 管理流程尚未实现。
- 多页面导航和二级菜单尚未实现。
- 当前开发机未提供 Docker，Compose 仅完成编排骨架，尚未在本机启动完整服务。
