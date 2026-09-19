# V0.1-dev.2 内容 API 设计

## 目标

把 V0.1-dev.1 硬编码在前端的学科/章节/知识点/热点数据落到 MySQL，由 Hyperf 提供查询 API；首页、学科页、章节页改为经 SSR 从 API 取数；消除 `data/home.ts` 与 `HealthController::home()` 的双份热点硬编码；为资料分类/年份/来源字段与本地资料清单关联预留 schema。

## 范围

- 五张表：`subjects`、`chapters`、`knowledge_points`、`hotspots`、`documents`；后两者与知识点带 `category`/`year`/`source` 字段。
- Hyperf 骨架修复（当前 `config/config.php`、`config/container.php`、`config/autoload/*` 缺失，应用无法启动）+ 数据库组件 + 迁移 + Seeder + Model/Service/Resource/Controller。
- 四个端点：`/api/v1/health`、`/api/v1/home`、`/api/v1/subjects`、`/api/v1/subjects/{slug}`。
- 前端 API 层（composable + utils + types + 测试）与三个页面切换。
- compose 给 api 补 `DB_*` 环境变量；README 增补 WSL2-Docker 工作流说明。

顺延至后续版本（不在本版本）：manifest→MySQL 的 Python 导入器、Meilisearch 索引（dev.3）、vue peer 依赖升级、compose profiles。

## 架构

- 迁移与种子：`apps/api/migrations/`（Hyperf `hyperf/db-connection` 迁移）、`apps/api/seeders/`（`db:seed`）。不用 schema.sql（initdb.d 只在 mysql 卷首次初始化生效、无法重放）。
- 分层：`apps/api/src/Model/`（Subject/Chapter/KnowledgePoint/Hotspot/Document）、`src/Service/`（SubjectService/HomeService）、`src/Resource/`（六个 Resource）、`src/Controller/`（SubjectController/HomeController/HealthController）。
- 路由：`apps/api/config/routes.php`，全部 GET，统一包络 `{ "data": ... }`；nginx 保留 `/api/` 前缀到 `api:9501`。
- 前端：`apps/web/composables/useApi.ts`（`useApiFetch`）、`apps/web/utils/api.mjs`（`unwrapEnvelope`/`toHomeSummary`）、`apps/web/types/api.ts`；页面用 `useFetch`，服务端 baseURL 走容器 DNS `http://api:9501/api/v1`，客户端走 `/api/v1`。
- 复用既有纯函数：`apps/web/utils/home.mjs`（`sortHotspots`/`buildHomeSummary`）、`apps/web/utils/subjects.mjs`（`getSubjectBySlug`/`validateSubjects`）；测试沿用 node:test + 内联 fixture 模式。

## 交互规则

1. 章节对外 id 用 `chapters.chapter_key`（即原 `SubjectChapter.id`，如 `materialism`），前端 `hotspots.chapterId` 与路由 `?chapter=` 零改动复用。
2. 热点 `updatedAt` 取 `hotspots.updated_at`，Resource 输出 `Y-m-d`；Seeder 用查询构造器显式写原日期。
3. 未知学科 slug 返回 404 `{ "message": "subject not found" }`。
4. `/api/v1/health` 的 `db` 字段用 `Db::select('select 1')` try/catch，失败降级为 `"fail"` 而非 500。
5. 前端 API 宕机时 `useFetch` 的 `default` 兜底渲染空态，不返回 500。
6. Seeder 全部 firstOrCreate 幂等；`hotspots.title` 有 UNIQUE 约束兜底。

## 数据内容

五表结构（InnoDB / utf8mb4 / utf8mb4_unicode_ci，含 `created_at`/`updated_at` DATETIME NULL）：

- `subjects`：id PK；slug VARCHAR(64) UNIQUE；name VARCHAR(128)；short VARCHAR(32)；tone VARCHAR(32) default 'jade'；detail VARCHAR(255) default ''；intro TEXT；asset_count INT default 0（首页摘要 count，种子 3/2/3/6/3/7）；sort_order INT default 0。
- `chapters`：id PK；subject_id FK→subjects.id CASCADE；chapter_key VARCHAR(64)；title VARCHAR(191)；summary VARCHAR(512) default ''；sort_order INT；UNIQUE(subject_id, chapter_key)。
- `knowledge_points`：id PK；chapter_id FK→chapters.id CASCADE；title VARCHAR(191)；summary VARCHAR(512) default ''；category VARCHAR(64) NULL；year SMALLINT NULL；source VARCHAR(191) NULL；sort_order INT；KEY(chapter_id)/(category)/(year)。
- `hotspots`：id PK；title VARCHAR(191) UNIQUE；level VARCHAR(2) default 'A'；summary VARCHAR(512) default ''；type VARCHAR(64) default ''；tag VARCHAR(64) default ''；subject_id FK→subjects.id CASCADE；chapter_id FK→chapters.id SET NULL；updated_at DATETIME NULL；KEY(subject_id)/(level, updated_at)。
- `documents`：id PK；title VARCHAR(191)；meta VARCHAR(191) default ''；progress TINYINT default 0；cover VARCHAR(32) default ''；tone VARCHAR(32) default 'jade'；category VARCHAR(64) default ''；year SMALLINT NULL；source VARCHAR(191) NULL；file_path VARCHAR(512) NULL UNIQUE（导入器预留）；subject_id FK→subjects.id SET NULL；sort_order INT；KEY(subject_id)/(category)/(year)。

JSON 契约（camelCase，与前端 TS 类型 1:1）：

- `SubjectResource` → `{ slug, name, short, tone, detail, intro, hotspots: string[], chapters: [ { id, title, summary, points: [ { title, summary } ] } ] }`
- `HotspotResource` → `{ level, title, summary, type, updatedAt, tag, subjectSlug, chapterId }`
- `DocumentResource` → `{ title, meta, progress, cover, tone }`
- `SubjectSummaryResource` → `{ slug, name, short, tone, detail, count }`
- `/api/v1/home` → `{ data: { hotspots[], documents[], subjects[] } }`；`/api/v1/subjects` → `{ data: SubjectDTO[] }`；`/api/v1/subjects/{slug}` → `{ data: SubjectDTO }`。

种子数据来源：`apps/web/data/subjects.ts`（6 学科 × 2 章 × 3 知识点）与 `apps/web/data/home.ts`（6 热点、2 文档卡、学科摘要 count 3/2/3/6/3/7）。时政学科显示名以 `subjects.name`（当代世界经济与政治）为准。

## 验证

- 容器链路（WSL2 原生副本 `~/guanlan`）：`docker compose up --build -d`；`docker compose logs api --tail 50` 无 Fatal；`curl http://localhost:8080/api/v1/health`、`/api/v1/subjects`、`/api/v1/subjects/marxism`、`/api/v1/home` 全 200 且含真实 DB 数据；`/api/v1/subjects/not-exist` 404；`/` 200 且 SSR HTML 含热点标题。
- 迁移/种子：`php bin/hyperf.php migrate --force`、`php bin/hyperf.php db:seed --force`；计数 subjects 6 / chapters 12 / knowledge_points 36 / hotspots 6 / documents 2；重复 seed 计数不变。
- 前端：`npm test`（subjects/home/api 三文件全绿）、`npm run build` 通过。
- 仓库：`git diff --check`、`git status` 无 vendor/runtime/node_modules/原始资料泄漏。验证结果须如实写入 CHANGELOG「验证」段。
