# 观澜主项目改造方案（支撑马原 / 史纲两子项目融入）

> 日期：2026-10-03 ｜ 状态：施工依据，待实施。
> 子项目任务书：`马原知识宇宙/GUANLAN_TASKS.md`、`近现代史时间轴/GUANLAN_TASKS.md`（两份均已写定，本方案是它们的观澜侧对应实现）。
> 技术栈现状：Nuxt 3 前端（`apps/web`）+ Hyperf 3.1/PHP 后端（`apps/api`）+ MySQL + nginx 单入口（`docker/nginx/production.conf`）。

## 一、总览

两个子项目采用**不同融入模式**，观澜侧改造分两块：

| 子项目 | 融入模式 | 观澜侧核心改造 |
|---|---|---|
| 马原知识宇宙 V2 | 同域 Bearer 直通 + 后端摘要推送 | 类型白名单、收藏幂等、笔记编辑、摘要接收端点、nginx 分流、入口 |
| 近现代史时间实验室 | 前端组件嵌入 + 观澜数据/学习接口 | 历史数据接口、PDF 文档服务、（共享）类型白名单/收藏幂等/笔记编辑、入口 |

**共享改造（两个项目都依赖，只做一次）**：类型白名单（6 类）、收藏幂等、笔记编辑、登录 redirect、入口卡片。

**架构决策（已定，不得变更）**：
- 不实现授权码/OAuth/独立服务身份（马原任务书方案 A 已废弃原需求 §3.3）。
- 马原详细学习记录留马原，观澜只收**摘要**；史纲无后端，学习记录直接进观澜 study 体系。
- 两子项目原创练习**均不写入**观澜 `/study/attempts`（避免污染真题统计）。
- 权威存储：账号/收藏/笔记/进度 = 观澜；马原详细记录 = 马原；史纲内容数据 = 观澜托管静态数据包。

## 二、共享改造（P0，两项目共用）

### 2.1 类型白名单扩展

**文件**：`apps/api/src/Service/StudyService.php`

`TARGET_TYPES` 当前 9 类，扩展为 15 类（加 6 个）：

```php
public const TARGET_TYPES = [
    'question', 'paper', 'analysis', 'hotspot', 'prediction',
    'mistake', 'mistake_item', 'handbook', 'mock',
    // 马原
    'mayuan_concept', 'mayuan_relation', 'mayuan_comparison', 'mayuan_experiment',
    // 史纲
    'history_event', 'history_comparison',
];
```

- 校验集中在 `StudyController` 的 `->in('targetType', StudyService::TARGET_TYPES, ...)`（收藏/笔记两处），改数组即可，无需改控制器。
- 字段长度已兼容：`favorites.target_id` varchar(191)、`title` 191、`url` 512，无需迁移。
- **工作量**：0.5 小时（后端）。前端类型映射见 §2.5。

### 2.2 收藏幂等接口

**问题**：现有 `POST /study/favorites` 是切换语义（`toggleFavorite`，重复调用会取消），网络重试不安全。

**新增**（不改动 POST，保持旧前端兼容）：

```
PUT /api/v1/study/favorites
Body: { targetType, targetId, title?, url?, favorited: bool }
→ 200 { data: { favorited: bool, id?: int } }
```

- `favorited: true`：不存在则创建，已存在则按 title/url 更新（幂等返回现状）。
- `favorited: false`：存在则删除，不存在则幂等返回 `{favorited:false}`。
- 校验同 POST（targetType 白名单、targetId required）。

**文件**：`apps/api/src/Controller/StudyController.php`（加 `setFavorite()`）、`apps/api/src/Service/StudyService.php`（加 `setFavorite()`）、`apps/api/config/routes.php`（加 `Router::put('/api/v1/study/favorites', ...)`）。

- **工作量**：0.5 天（含单测）。

### 2.3 笔记编辑接口

**问题**：现有笔记只有新建/删除，无编辑。

**新增**：

```
PATCH /api/v1/study/notes/{id}
Body: { content: string }   （≤20000）
→ 200 { data: Note } ｜ 404
```

- 仅允许编辑本人笔记（`where('user_id', Auth::user()->id)`）。
- 只更新 `content`（title/targetType/targetId 不可改，避免变更归属）。

**文件**：`StudyController::updateNote()`、`StudyService::updateNote()`、routes。

- **工作量**：0.5 天（含单测）。

### 2.4 登录 redirect

**问题**：`pages/login.vue` 无 redirect 参数，子项目深链登录后回不到原目标。

**改造**：登录页读取 `?redirect=/mayuan/...` 或 `/history/...`，登录成功 `navigateTo(redirect)`；校验 redirect 必须是站内相对路径（`/` 开头、非 `//`），否则回默认页，防开放跳转。

**文件**：`apps/web/pages/login.vue`（login/register 成功后跳转）、`apps/web/composables/useAuth.ts`（login/register 返回后由页面跳转，或加 redirect 参数透传）。

- **工作量**：0.5 天。

### 2.5 前端类型映射 + 外部/跨页跳转

**问题**：`pages/me/favorites.vue:40-41`、`pages/me/notes.vue:39-40` 直接渲染 `targetType` 原文（会显示 `mayuan_concept`），且用 `NuxtLink :to="item.url"`——子项目对象 url 是站内子路径（`/mayuan/...`、`/history/...`），NuxtLink 可处理站内路径，但需确认不拦截。

**改造**：
- 新建类型→中文名映射（`apps/web/utils/targetType.ts` 或 composable）：
  ```ts
  { mayuan_concept:'马原·概念', mayuan_relation:'马原·关系', mayuan_comparison:'马原·辨析',
    mayuan_experiment:'马原·实验', history_event:'史纲·事件', history_comparison:'史纲·对照', ...现有9类 }
  ```
- 收藏/笔记列表用它显示中文标签。
- url 跳转：`/mayuan/`、`/history/` 是观澜 nginx 分流到子应用的路径，**不能用 NuxtLink**（会走 Nuxt 路由 404）。需改为：url 以这两个前缀开头时用普通 `<a href>`（整页跳到子应用），其余站内 Nuxt 路由仍用 NuxtLink。

**文件**：`apps/web/pages/me/favorites.vue`、`apps/web/pages/me/notes.vue`、新增映射工具。

- **工作量**：0.5-1 天（含笔记编辑 UI，见下）。

**配套**：`me/notes.vue` 加编辑入口（行内编辑或弹窗），调 §2.3 的 PATCH。

### 2.6 首页入口卡片

**文件**：`apps/web/pages/index.vue`（+ 可能 `SiteHeader.vue` 导航）。

加两张入口卡片：「马原知识宇宙」→ `/mayuan/`、「近现代史时间实验室」→ `/history/`。名称/描述/图标走 `nuxt.config.ts` 的 `runtimeConfig.public`（`mayuanBase`、`historyBase` 环境变量，不写死）。

- **工作量**：0.5 天。

## 三、马原专项改造（P0）

### 3.1 摘要接收端点

**新建迁移** `2026_10_XX_000001_create_mayuan_summaries_table.php`：

```
mayuan_summaries
  id, user_id (unique, FK users cascade),
  revision (unsigned int), content_version (varchar 191),
  visited_concept_count, self_assessed_mastered_count,
  practice_attempt_count, practice_correct_count, due_review_count (均 unsigned int),
  last_event_id (varchar 191),
  last_activity_at (datetime null), resume_target (json/text null),
  created_at, updated_at
```

**新建路由 + 控制器**：

```
PUT /api/v1/integrations/mayuan/summary   （AuthMiddleware + RequireAuthMiddleware，人态）
GET /api/v1/study/mayuan/summary          （人态读自己）
```

**PUT 逻辑**（`Integrations/MayuanController::putSummary` 或并入 StudyController）：
- 用户从 `Auth::user()` 取，**请求体不接受 userId**（防代写他人）。
- 校验：`schemaVersion===1`、`eventId` 非空合法、`revision` 为非负 int、各计数非负、`practiceCorrectCount <= practiceAttemptCount`、`resumeTarget.view/nodeId` 结构合法。
- **revision 防旧覆盖新**：`revision <= 库存 revision` → 409。
- **eventId 幂等**：`eventId === 库存 last_event_id` → 直接返回 `{data:{revision:库存revision}}`（重试不重复）。
- 通过则 upsert（绝对值覆盖）+ 存 `last_event_id`，返回 `200 {data:{revision}}`（值 = 被接受 revision，供马原 outbox 确认）。

**GET 逻辑**：返回当前用户摘要（无则 404 或空对象），字段 camelCase + `updatedAt`。

**文件**：迁移、`apps/api/src/Controller/`（新控制器或并入）、routes、`apps/api/src/Model/MayuanSummary.php`（新模型）。

- **工作量**：1.5-2 天（含校验、防重、单测：有效/旧版本 409/重复 eventId 幂等/计数越界 422/越权无 userId 字段）。

### 3.2 摘要展示

**文件**：`apps/web/pages/me/index.vue`（或新卡片组件）。

调 `GET /study/mayuan/summary`，渲染卡片：自评已掌握 X / 练习正确率 Y%（**分开标注**，自评不叫"掌握率"）、待复习 Z、继续学习按钮（跳 `/mayuan/` 带 resumeTarget）。无数据显示"去探索马原宇宙"。

- **工作量**：0.5-1 天。

### 3.3 nginx 分流

**文件**：`docker/nginx/production.conf`

```nginx
location /mayuan/ { proxy_pass http://mayuan-web:3000/; /* 标准头 */ }
location /api/v2/ { proxy_pass http://mayuan-api:9501; /* 标准头 + 长超时可不配 */ }
```

- 与现有 `/api/`（观澜 `/api/v1/`）按前缀区分，不冲突。
- 马原 SPA 需 try_files 回退（在 mayuan-web 侧或 nginx 配）。
- **注意**：这是 docker-compose 编排，马原 service（`mayuan-web`/`mayuan-api`）要加进观澜 `docker-compose.yml`。本机无 Docker，配置改好后需在有 Docker 环境验证。

**文件**：`docker/nginx/production.conf`、`docker-compose.yml`（加马原服务）。

- **工作量**：0.5-1 天（配置 + 文档，验证靠部署环境）。

## 四、史纲专项改造（P0）

### 4.1 历史数据接口

```
GET /api/v1/history/events
（AuthMiddleware 仅解析身份，不强制登录；只读）
→ 200 { data: <HistoryDataset> }
```

- `HistoryDataset` 结构 = 史纲 `data/history.v1.json` 顶层字段（`version/title/range/topics/sources/events/comparisons/generatedAt`），**结构不变**。
- 存储：新建 `history_datasets` 表（id, version unique, payload json/longtext, status, created_at）后台可发布新版本；或简化放 `storage/history/history.v1.json` 按文件读 + 版本号缓存。**建议建表**，便于后台更新与版本管理。
- 版本号 = 数据集 `version` 字段，更新必须递增（前端据此失效旧缓存）。
- 待审核数据（34 项对照）**不进入**该接口正式 events。

**文件**：迁移、`HistoryController::events()`、`HistoryService`（读最新已发布版本）、routes、（可选）admin 发布入口。

- **工作量**：1-1.5 天（含数据导入 seed、版本读取；admin 发布 UI 可后续）。

### 4.2 PDF 文档服务

**决策点**（需先定，见 §六）：静态 or 鉴权 API。

- **静态（推荐，教材可公开）**：PDF 放观澜 nginx 公开目录 `/documents/original.pdf`、`/documents/textbook.pdf`，支持 Range。观澜零代码，仅部署放文件 + nginx location。
- **鉴权 API**：`GET /api/v1/documents/{documentId}` 带 token + Range 流式返回。需新控制器读存储文件流（类似史纲 vite 中间件的 Range 逻辑）。

`resolveSourceUrl(sourceId, page)` 由史纲前端实现，观澜只需保证 URL 可达。

- **工作量**：静态 0.5 天（部署文档）；鉴权 API 1-1.5 天（控制器 + Range + 鉴权）。

### 4.3 前端嵌入承载

**文件**：`apps/web/pages/history/index.vue`（新建）、`apps/web/components/history/`（史纲 history-ui 源码复制，或 workspace 引入）。

- `pages/history/index.vue` 用 `<ClientOnly>` 挂载史纲 `createGuanlanHistoryApp(...)`，注入 repository（指 §4.1）、studyRepository（指 §2 共享接口）、router（Nuxt query 适配）。
- 史纲组件源码复制进 `apps/web/components/history/`（融入彻底，单向同步自上游仓库）。
- 视觉独立（档案馆风），观澜只提供容器。

- **工作量**：1.5-2 天（含路由适配联调；组件本体史纲侧提供）。

## 五、实施顺序与排期

```
Week 1（共享层，不依赖子项目进度，可先合并）
  Day 1   §2.1 类型白名单 + §2.2 收藏幂等 + §2.3 笔记编辑（后端 + 单测）
  Day 2   §2.4 登录 redirect + §2.5 类型映射/跳转/笔记编辑 UI（前端）
  Day 3   §2.6 入口卡片 + §3.1 摘要迁移与端点（开始）
  Day 4   §3.1 摘要端点完成 + 单测
  Day 5   §3.2 摘要卡片 + §3.3 nginx/compose 配置

Week 2（史纲 + 联调）
  Day 1-2 §4.1 历史数据接口（迁移 + 读取 + seed 导入）
  Day 3   §4.2 PDF 服务（按决策）+ §4.3 嵌入承载（开始）
  Day 4-5 §4.3 嵌入承载联调 + 两子项目端到端联调 + 回归
```

**观澜侧总工作量**：约 8-11 天（不含部署环境 Docker 验证）。

## 六、动工前需确认的 3 个决策

1. **PDF 服务方式**：静态（简单、可公开下载）还是鉴权 API（可控）？教材 PDF 是否允许公开？默认建议静态。
2. **史纲数据存储**：`history_datasets` 建表（推荐，可后台版本管理）还是文件存储（简单）？
3. **史纲组件引入方式**：源码复制进 `apps/web/components/history/`（推荐，融入彻底）还是 npm workspace 引用（保持独立但构建复杂）？

## 七、验收清单（观澜侧）

- [ ] 6 个新类型可正常收藏/记笔记，列表显示中文标签，跳转正确。
- [ ] 收藏重试（PUT 幂等）不意外取消；笔记可编辑不产生重复。
- [ ] 登录 redirect 到 `/mayuan/`、`/history/` 深链，非站内路径被拒绝。
- [ ] 摘要 PUT：有效写入、旧 revision 409、重复 eventId 幂等、计数越界 422、无法代写他人；GET 读自己。
- [ ] 个人中心显示马原摘要卡片（自评/正确率分开）。
- [ ] nginx `/mayuan/`、`/api/v2/`、`/history/`（如嵌入观澜则无需独立路径）分流正确。
- [ ] `/api/v1/history/events` 返回 `{data:HistoryDataset}`，版本递增，待审核数据不外泄。
- [ ] PDF 按选定方式可达（Range 正常），无本地盘符泄露。
- [ ] 现有真题/错题/收藏/笔记/登录功能回归通过。
- [ ] 两个真实账号验证数据隔离。

## 八、关键文件索引

| 改造 | 文件 |
|---|---|
| 类型白名单 | `apps/api/src/Service/StudyService.php` |
| 收藏幂等/笔记编辑 | `apps/api/src/Controller/StudyController.php`、`StudyService.php`、`config/routes.php` |
| 摘要 | 新迁移、`apps/api/src/Model/MayuanSummary.php`、控制器、routes |
| 历史数据 | 新迁移、`HistoryController.php`、`HistoryService.php`、routes |
| 登录 redirect | `apps/web/pages/login.vue`、`composables/useAuth.ts` |
| 类型映射/跳转 | `apps/web/pages/me/favorites.vue`、`me/notes.vue`、新 `utils/targetType.ts` |
| 摘要卡片/入口 | `apps/web/pages/me/index.vue`、`pages/index.vue`、`nuxt.config.ts` |
| 史纲嵌入 | `apps/web/pages/history/index.vue`、`apps/web/components/history/` |
| 部署 | `docker/nginx/production.conf`、`docker-compose.yml` |
