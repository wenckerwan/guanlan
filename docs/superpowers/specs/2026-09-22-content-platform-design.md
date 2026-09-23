# V0.1-dev.3 内容平台设计（真题 / 时政 / 错题 / 模拟 + 前后台）

> 本文件是 V0.1-dev.3 的接口与数据契约。**冻结后**，前端、后端与导入器三方按此并行开发；
> 任何字段变更必须先改本文件，再改代码。

## 一、目标

把 `F:\2027考研资料\考研政治\` 下的五类资料接入网站，并补齐「前端 + 后台 + 登录注册」：

| 模块 | 数据来源（只读） | 网站形态 |
|---|---|---|
| 真题回顾 | `真题库v2/data/questions.jsonl`（1479 题） | 按年份/模块筛选、在线答题、自动判分 |
| 真题分析 | `真题分析_2012-2026/*.md` | 长文阅读（Markdown → HTML） |
| 时政热点 | `时政热点/时政考点_*.md` | 按月/优先级浏览 |
| 时政预测 | `时政热点/时政考点预测_真题反推/*.md` + `真题分析/2027考研政治时政热点预测.md` | 长文阅读 |
| 个人错题分析 | `个人错题分析/`（考生 A/B） | 考生隔离的错题册、重做、提分手册 |
| 模拟押题 | `船/2027考研政治模拟卷（一）.md` | 在线作答 + 判分 |

## 二、范围

**本期做**：离线导入器 → MySQL → `/api/v1` 只读内容接口 + 用户态接口（注册/登录/收藏/笔记/答题记录/进度） + 管理后台接口与页面 + Nuxt 前台六个板块页面。

**本期不做（顺延）**：Meilisearch 全文检索（先用 SQL LIKE + 索引）、文件上传、PDF 在线阅读器。

## 三、架构

```
F:\2027考研资料\考研政治\  (只读)
        │  python tools/ingest/build_all.py
        ▼
storage/dataset/*.json     (派生数据，入库版本库)
        │  php bin/hyperf.php db:seed --force
        ▼
MySQL 8.4 ── Hyperf 3.1 /api/v1 ── Nuxt 3 SSR ── nginx :8080
```

- 统一包络 `{ "data": ... }`；错误 `{ "message": "..." }` + 对应状态码。
- 对外字段**一律 camelCase**；数据库列 snake_case。
- 认证：`Authorization: Bearer <token>`，token 为 32 字节随机串的 sha256，存 `user_tokens`。
- 密码：`password_hash(PASSWORD_DEFAULT)`。

## 四、数据表

### 内容（只读）

| 表 | 关键列 |
|---|---|
| `subjects` / `chapters` / `knowledge_points` | 沿用 dev.2 |
| `papers` | `pid`(uk), `year`, `label`, `kind`, `question_count`, `total_score`, `sections`(json) |
| `questions` | `pid`+`no` 唯一；`year`, `module`, `module_name`, `type`, `kaodian`, `stem`, `options`(json), `answer`, `analysis`, `score`, `material` |
| `analysis_articles` | `slug`(uk), `title`, `category`, `summary`, `html`(longtext), `source_file`, `word_count`, `sort_order` |
| `hotspots` | 沿用 dev.2，新增 `slug`(uk), `html`(longtext), `period`, `priority`, `published_at` |
| `predictions` | `slug`(uk), `title`, `summary`, `html`, `layer`, `source_file` |
| `mistake_students` | `code`(uk), `name`, `relation` |
| `mistake_items` | `student_id`, `batch`, `module`, `chapter`, `source_no`, `kaodian`, `stem`, `options`(json), `my_answer`, `correct_answer`, `error_type`, `action` |
| `mistake_handbooks` | `student_id`+`module` 唯一；`title`, `html`, `sections`(json) |
| `mocks` | `slug`(uk), `title`, `summary`, `total_score`, `duration_minutes` |
| `mock_questions` | `mock_id`+`no` 唯一；字段同 `questions` |

### 用户（读写）

| 表 | 关键列 |
|---|---|
| `users` | `email`(uk), `password_hash`, `display_name`, `role`(user/admin), `status`(active/disabled) |
| `user_tokens` | `user_id`, `token_hash`(uk), `expires_at` |
| `favorites` | `user_id`+`target_type`+`target_id` 唯一；`target_type` ∈ question/analysis/hotspot/prediction/mistake_handbook |
| `notes` | `user_id`, `target_type`, `target_id`, `content` |
| `attempts` | `user_id`, `source`(paper/mock/mistake), `source_ref`, `question_ref`, `chosen`, `correct`, `is_right` |
| `study_progress` | `user_id`+`scope`+`ref` 唯一；`status`, `correct_count`, `wrong_count`, `last_seen_at` |

## 五、接口

### 5.1 认证 `/api/v1/auth`

| 方法 | 路径 | 入参 | 出参 |
|---|---|---|---|
| POST | `/register` | `email, password, displayName?` | `{token, user}` 201；重复邮箱 409 |
| POST | `/login` | `email, password` | `{token, user}`；失败 401 |
| GET | `/me` | — | `{user}`；未登录 401 |
| POST | `/logout` | — | `{ok:true}` |

`user = {id, email, displayName, role, createdAt}`。

### 5.2 内容只读

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/home` | 沿用；`hotspots` 改取 `hotspots` 表，新增 `stats`（题目数/试卷数/热点数/错题数） |
| GET | `/subjects`、`/subjects/{slug}` | 沿用 |
| GET | `/papers?year=&module=&q=` | 试卷列表 |
| GET | `/papers/{pid}?reveal=0\|1` | 试卷详情；`reveal=0` 时 `answer`/`analysis` 为 `null` |
| GET | `/questions?module=&year=&type=&kaodian=&q=&page=&perPage=` | 分页；出参 `{items, total, page, perPage}` |
| GET | `/questions/{id}?reveal=` | 单题 |
| GET | `/analysis`、`/analysis/{slug}` | 真题分析文章 |
| GET | `/hotspots?period=&priority=`、`/hotspots/{slug}` | 时政热点 |
| GET | `/predictions`、`/predictions/{slug}` | 时政预测 |
| GET | `/mistakes/students` | 考生列表（仅 `code, name, relation, itemCount`） |
| GET | `/mistakes/students/{code}` | 考生 + 批次统计 |
| GET | `/mistakes/students/{code}/items?module=&errorType=` | 错题明细 |
| GET | `/mistakes/students/{code}/handbooks`、`/mistakes/handbooks/{id}` | 提分手册 |
| GET | `/mocks`、`/mocks/{slug}?reveal=` | 模拟卷 |
| GET | `/stats/overview` | 图表数据（模块频率/考点 TOP/多选组合/结构） |

### 5.3 用户态（需登录）

| 方法 | 路径 | 说明 |
|---|---|---|
| POST/DELETE | `/study/favorites`、`/study/favorites/{id}` | 收藏 |
| GET | `/study/favorites?targetType=` | 我的收藏 |
| GET/POST/DELETE | `/study/notes`、`/study/notes/{id}` | 笔记 |
| POST | `/study/attempts` | `{source, sourceRef, questionRef, chosen, correct}` → 落库并回写进度 |
| GET | `/study/stats` | 我的做题统计（总数/正确率/按模块） |
| GET | `/study/progress` | 进度列表 |
| POST | `/study/progress` | upsert 进度 |

### 5.4 管理后台（需 `role=admin`）

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/admin/overview` | 各表行数、今日新增用户 |
| GET | `/admin/users?q=` | 用户列表 |
| PATCH | `/admin/users/{id}` | 改 `role`/`status` |
| GET/POST/PATCH/DELETE | `/admin/hotspots[/{id}]` | 热点增删改 |
| GET/POST/PATCH/DELETE | `/admin/analysis[/{id}]` | 分析文章增删改 |
| GET | `/admin/attempts?limit=` | 全站答题记录 |
| GET | `/admin/mistakes` | 错题数据概览 |

## 六、种子管理员

`db:seed` 创建 `admin@guanlan.local` / `guanlan2027`（`role=admin`），并在 CHANGELOG 标注首次登录后须改密。

## 七、验证

- 容器链路：`docker compose up --build -d` → 迁移 + 种子成功；`/api/v1/health` 返回 `db:ok`。
- 端点：逐条 `curl` 断言状态码与关键字段（含 401/403/404/409 负例）。
- 前端：`npm test` 通过、`npm run build` 通过、关键页面 SSR HTML 命中数据。
- 后台：登录管理员 → 列表可见 → 增删改各一次成功。
