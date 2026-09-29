# 更新记录

## V0.1-dev.9 生产部署与容器验证 - 2026-09-29

服务器 root-189（/www/wwwroot/guanlan，宝塔 + Docker Compose）实际执行结果。

### 部署内容

- `git reset --hard` 对齐重写后的 `origin/v0.1-dev.7`（历史清理 + dev.8/9 全量内容）。
- **`composer require hyperf/guzzle`**（composer:2 容器内执行）：安装 hyperf/guzzle
  v3.1.66 + guzzlehttp/guzzle 7.15.5，AI 分析后端启用。提交 10c3752。
- 重建 api/web 镜像并重启；api 启动链自动跑 `verify-dataset → migrate --force →
  db:seed --force`。三个新迁移落库确认：`admin_audit_logs` 表、
  `mistake_reviews.consecutive_correct` 列、`hotspots/analysis_articles.status` 列。
- `/api/v1/health` 返回 `{"status":"ok","db":"ok","version":"V0.1-dev.9"}`。

### 部署过程中发现并修复的问题

1. **数据集 manifest 漂移（05a29e1）**：dev.6/7 提交 038ae9a 更新了 `mistakes.json`
   但未重生成 manifest（253087 字节实际 vs 253077 声明），容器 `verify-dataset`
   启动失败、api 崩溃循环。用 `tools/ingest/dataset_manifest.py` 的 build_manifest
   正式重生成，8 个数据集 + totals 全部对账一致。此前生产是靠服务器上未提交的手工
   manifest 修改在撑——重置工作区后暴露。
2. **管理员账号失效**：`admin@guanlan.local` 在 9/28 被普通注册抢占，MistakeSeeder
   的 seedAdmin 见邮箱已存在即跳过，导致系统无管理员、全部后台接口 403。已修数据
   （升级 role=admin）并修 seeder：邮箱被占时升级为管理员而非跳过。
3. **后台热点/分析编辑失效（遗留 bug）**：`ArticleResource::listItem` 从不输出
   `id`，而后台页的改状态/删除按钮依赖 `item.id`——该功能自上线起即不可用。资源
   已补 `id` 字段。
4. **/health 版本号硬编码**：改为读仓库根 VERSION 文件（镜像同步 COPY）。
5. **测试可运行性**：`AIAnalysisTest` 原 PHPUnit 风格但项目从未引入 phpunit，从来
   跑不起来——重写为项目统一的纯脚本风格（桩件、无外部依赖），并补 8 组 SSRF 拦截
   用例；`DatasetManifestVerifierTest` 原 shell 出调 python3 构建 fixture，api 容器
   无 python——改为纯 PHP 复刻 builder 算法。

### 容器验证记录（实际输出）

- `php -l` 全量：**lint checked=122 failed=0**（api 容器内执行）。
- PHP 测试 6/6：AIAnalysisTest / AccountIdTest / AdminCredentialsTest /
  ContentStatusTest / DatasetManifestVerifierTest / MistakeAccessTest 全部 PASS。
- `bash tools/smoke.sh`（生产网关 127.0.0.1:8080）：49 个用例 200/201；所有 4xx 均为
  预期（不存在资源 404、未登录 401、校验 422、重复注册 409）。第 10 节后台全矩阵
  通过：admin 全接口 200 / 无 token 401 / 非管理员 403；隐藏热点详情 404 → 恢复发布
  200；非法 status 回退 published；B1 profile 上传回读一致；B3 PATCH 200/403/404/422；
  B2 review-stats 200。
- 前端 43/43 node 测试通过（本机）。

## V0.1-dev.9 - 错题后台闭环 B1 - 2026-09-29

对应 [admin-update-plan](docs/superpowers/plans/2026-09-29-admin-update-plan.md) 阶段 B1 + 审计表前置。
分支 `feature/admin-mistake-console`（叠于 dev.8 之上）。

### 变更

- **审计表前置**：迁移 `2026_09_29_000003` 建 `admin_audit_logs`
  （admin_id / action / target_type / target_id / detail / created_at，双索引），
  新增 `AdminAuditService::log()`。阶段 B/C 的后台写操作统一走它，本期接入 profile 上传。
- **接通错题分析断头链路**：`MistakeController::detail` 原先读
  `students.detail_html`（注册生成的考生恒为空串），`mistake_profiles` 只写不读。
  现改为优先返回 profile 内容（`isDefault=false` 时），回退 `detail_html`；
  响应新增 `isDefault` / `updatedAt`。考生端错题本页新增「错题分析」折叠卡片，
  默认占位内容不渲染——管理员上传后考生端立即可见（验收标准①）。
- **后台错题接口**（全部在 `RequireAdminMiddleware` 组内，非管理员 403 = 验收标准②）：
  - `GET /admin/mistakes/students`：全量考生（含数据集考生 A），带错题数、模块/错因分布、
    绑定账号邮箱、来源标记；
  - `GET /admin/mistakes/students/{code}/items`：条目分页浏览（perPage ≤ 100）；
  - `GET /admin/mistakes/students/{code}/profile`：当前 Markdown 与来源信息；
  - `PUT /admin/mistakes/students/{code}/profile`：落 `MistakeProfileService::replace()`，
    记录 `source_file` 与 `updated_by`（验收标准③），并写审计日志
    `mistake.profile.replace`。
- **前端**：新增 `admin/mistakes.vue`（考生列表 → 条目分页浏览 → Markdown 编辑器：
  .md 文件上传或直接粘贴、来源文件名、保存发布），后台导航加「错题」入口。
- **B3 错题条目维护**：`PATCH /admin/mistakes/items/{id}`，管理员可改全局
  `action` / `errorType` / `module`（空请求 422、至少提供一个字段），写审计
  `mistake.item.update`（记录实际写入字段）。这是管理员改全局字段的正式通道，
  替代 dev.7 修复中「普通用户降级写全局 action」的旧路径遗留场景。
- **B2 复习数据看板**：`GET /admin/mistakes/review-stats`，按考生汇总错题数、
  学习者数、复习次数、正确率、new/reviewing/mastered/snoozed 分布与逾期数；
  单条 SQL（students 左联 items 左联 reviews，一次 group by），不逐用户循环。
  前端在错题后台页内嵌看板表格，逾期数红色高亮。
- **条目编辑 UI**：条目表新增「编辑」入口，行内表单修改行动建议/错因/模块，
  保存后本地同步更新。

### 验证

本机（Windows，无 PHP/Docker）实际执行：

- `python tools/phpcheck.py`：checked=122 files, classes=80, tables=25，OK。
- `npm test --prefix apps/web`：43/43 通过。
- `python tools/doclink.py`：断链仍为改动前已存在的 README→docs/api.md，无新增。
- `bash -n tools/smoke.sh`：语法通过；新增 B1 用例（后台考生列表/条目/profile GET、
  PUT 上传后回读 sourceFile 一致、非管理员 403 × 2、考生端 detail 200 且带内容），
  待容器内执行。
- 审计落库断言依赖真实数据库，待容器冒烟覆盖。
- B3 冒烟补例：PATCH 200 且回读一致、非管理员 403、不存在 404、空字段 422；
  B2 冒烟补例：review-stats 200。待容器内执行。

## V0.1-dev.8 - 后台内容管理补齐 - 2026-09-29

对应 development-plan 1.6，规划见 [superpowers/plans/2026-09-29-admin-update-plan.md](docs/superpowers/plans/2026-09-29-admin-update-plan.md) 阶段 A。

### 变更

- **内容状态字段**：迁移 `2026_09_29_000002` 给 `hotspots` / `analysis_articles` 加
  `status`（`published`/`hidden`，默认 published 并回填存量行）。新增
  `App\Support\ContentStatus` 统一口径：后台写入非法值回退 published，前台仅
  `hidden` 不可见。
- **前台出口全量过滤**：热点与分析的列表、详情（404）、首页热点卡片、全站搜索
  （`SearchService::articles`）对 hidden 内容统一隐藏；后台列表仍可见可改。
  注意：部署时必须先跑迁移再启动 API，否则旧行 NULL 会被 `<> hidden` 条件排除。
- **后台 CRUD 接入状态**：`saveHotspot` / `saveAnalysis` 接受并归一化 `status`；
  `ArticleResource` 输出 `status` 字段；热点/分析后台页新增「已发布/已隐藏」切换按钮。
- **后台只读查看**：新增 `GET /admin/papers`（分页）、`GET /admin/papers/{pid}/questions`
  （分页）、`GET /admin/mocks`、`GET /admin/predictions`，全部在 `RequireAdminMiddleware`
  组内，复用现有 Resource；前端新增 `admin/papers.vue`（含题目展开）、`admin/mocks.vue`、
  `admin/predictions.vue`，导航增加真题/押题/预测三项。数据集内容明确只读。
- **总览增强**：`overview` 增加 `registrationTrend`（近 14 天按天注册数）与
  `mistake_reviews` 计数；前端总览页渲染趋势柱状图；新增 `utils/trend.mjs`
  （`lastNDays` / `fillTrend` / `trendTotal` 纯函数）。
- **冒烟补例**：`tools/smoke.sh` 第 10 节增加 4 个后台只读接口、无 token 401、
  隐藏热点详情 404 → 恢复发布 200、非法 status 回退 published 的用例。

### 验证

本机（Windows，无 PHP/Docker）实际执行：

- `python tools/phpcheck.py`：checked=120 files, classes=79, tables=23，OK。
- `npm test --prefix apps/web`：43/43 通过（新增 trend 纯函数 5 组用例）。
- `python tools/doclink.py`：断链 7 处，均为改动前已存在（HEAD 上输出一致），无新增。
- `bash -n tools/smoke.sh`：语法通过。
- 新增 `apps/api/tests/ContentStatusTest.php`（normalize/isVisible 共 12 组断言），
  已接入项目测试目录；因本机无 PHP 未实际执行，待容器 `tools/lint.sh` 验证。
- `bash tools/smoke.sh` 与迁移 `2026_09_29_000002` 待容器内执行。

## 错题板块修复 - 2026-09-29

### 变更

- **AI 服务懒加载（P0）**：`MistakeController` 不再在构造函数注入 `AIAnalysisService`
  （其依赖 `Hyperf\Guzzle\ClientFactory`，而 `hyperf/guzzle` 未入库，曾导致错题模块
  全量 500）。改为请求时经容器懒加载，包缺失时返回 503 提示，错题核心功能不受影响。
  启用 AI 分析需在生产执行 `composer require hyperf/guzzle`。
- **行动建议写权限拆分**：全局 `action` 仅管理员可改；普通用户的行动建议写入
  `mistake_reviews.personal_action`（列此前已存在但未使用）。错题列表接口按登录态
  合并返回 `personalAction`，前端展示优先级：本次会话 > 个人建议 > 全局建议。
- **复习统计去重**：`MistakeReview::isDueToday()` 改为仅统计「今天之内」到期，
  逾期的归入 `overdue`，修复前端 `dueToday + overdue` 双重计数。
- **真实连胜算法**：`mistake_reviews` 新增 `consecutive_correct` 列（迁移
  `2026_09_29_000001`），答错清零、答对 +1，达到 3 判定已掌握。替换原先用累计
  `correct_count` 近似的错误逻辑（该逻辑下「对对错对」会直接跳到已掌握）。
- **`updateReviewStatus` 容错**：无复习记录时自动创建（原先 `firstOrFail` 抛 500）；
  `nextReviewAt` 先经 `strtotime` 校验，非法格式返回 422。
- **AI 出网校验（SSRF）**：`AIAnalysisService` 对用户输入的 `baseUrl` / `endpoint`
  校验协议与主机，拒绝内网、保留 IP 与 `.local` / `.internal` 域名。
- **旧账号编号兼容**：`MistakeAccess::normalizeCode()` 对纯数字编号去前导零再比较，
  旧格式 `mistake_code`（如 `2`）可命中新格式错题本编号（`000002`）；带空白的编号
  仍拒绝。`allowedCodes` 同时返回两种形式。
- **并发首访**：`ensureReviews` 改用 `insertOrIgnore`，依赖唯一索引幂等。
- **前端**：复习概览 `mastered/total` 在 total 为 0 时不再显示 NaN%；AI 分析页
  Claude 直连补 `anthropic-dangerous-direct-browser-access` 头修复 CORS。

### 验证

- `python tools/phpcheck.py`：checked=118 files, classes=78, tables=23，OK。
- `npm test --prefix apps/web`：38/38 通过。
- `MistakeAccessTest` 补充 5 个编号归一化用例（旧新格式互通、空白拒绝、前导零不串号）。
- 本机无 PHP/Docker：`php -l`、PHP 测试与 migration 实际执行待部署环境跑
  `tools/lint.sh` 与 `db:migrate`。

## 错题可见性、访客配额与登录态 SSR - 2026-09-27

### 变更

- **错题可见性收紧**：新增 `App\Support\MistakeAccess`，考生 A 为公开模板，
  其余编号仅「绑定账号」与管理员可见。覆盖 `students` / `show` / `items` /
  `handbooks` / `detail` / `handbooks/{id}` / `items/{id}/action` 七个入口，
  其中 `handbooks/{id}` 反查手册归属，避免绕过 code 直取私有手册。
- **全站搜索防泄露**：`/search` 的错题结果按可见编号白名单过滤。
- **账号 ID**：`users` 新增 `mistake_code`（可空、唯一）。注册时取最小未占用的
  纯数字编号（1、2、3…），一 ID 绑定一账号并对应同编号错题本；唯一索引兜底并发，
  冲突最多重试 5 次。个人中心展示该 ID，后台用户列表可查看与改绑（撞号返回 422）。
- **访客配额**：新增 `App\Support\GuestQuota`，真题分析 / 时政热点 / 时政预测
  各自可免费阅读 3 篇。列表仍返回全部条目并带 `locked`，超额详情返回 403。
  配额按「栏目整体顺序」计算，与列表筛选条件无关，保证列表与详情边界一致。
- **登录引导**：新增 `LoginGateModal.vue`。锁定卡片点击即弹窗；详情被 403 拦下会
  跳回对应列表并带 `?login=1` 自动弹窗，「去登录」携带 `redirect` 回跳原页。
- **登录态迁移 Cookie**：token 从 localStorage 迁到 Cookie（30 天，`SameSite=Lax`，
  HTTPS 加 `Secure`），SSR 首帧即可解析身份，消除已登录用户的游客态闪烁；
  `useApiFetch` 透传 Authorization，缓存 key 含 URL 与 token。
- **错题列表公告**：`/mistakes` 顶部提示错题分析服务消耗 token、暂不支持免费分析。
- **移除考生 B**：`mistakes.json`、`dataset-manifest.json`、
  `tools/ingest/build_mistakes.py` 三处同步移除，避免重新生成时回归。
- **修复**：`/mistakes/{code}.vue` 的「看提分手册」链接指向不存在的
  `/mistakes/{code}/handbook/{id}`，已改为实际路由 `/mistakes/{code}/{id}`。
- **修复**：列表页原先在前端按优先级重排序，会让服务端算好的 `locked` 分界错位；
  现改为顺序完全由服务端决定。

### 验证

本机（Windows，无 Docker；WSL 无 PHP）实际执行：

- `php -l`（`src/config/migrations/seeders/tests/bin` 共 110 个文件）：0 失败。
- `php tests/MistakeAccessTest.php`：`MistakeAccessTest: PASS`；
  把 `GuestQuota::FREE_PER_SECTION` 改成 +10 后该测试 FAIL，确认能抓到配额破坏。
- `php tests/AccountIdTest.php`：`AccountIdTest: PASS`；
  把 `isDuplicateMistakeCode` 改名后该测试 FAIL，确认能抓到重试逻辑缺失。
- `php bin/verify-dataset.php`：`Dataset integrity OK: 8 files`；
  另用临时副本篡改 `mistakes.json.items` 后返回
  `mistakes.json.items mismatch`，负向用例有效。
- `python tools/phpcheck.py`：`checked=104 files, classes=70, tables=20`，`OK`。
- `python -m unittest discover -s tools/ingest -p "test_*.py"`：20/20 通过。
- `npm test --prefix apps/web`：38/38 通过（新增 `splitByLock` 两组用例）。
- `npm run build --prefix apps/web`：Nuxt 生产构建成功，`Σ Total size: 5.19 MB (1.35 MB gzip)`。
- SSR 冒烟：`node .output/server/index.mjs` 启动后
  `/ /mistakes /analysis /hotspots /predictions /login` 全部 200，stderr 无报错。

### 已知限制

- 本机无 Docker、WSL 无 PHP，以下未执行也未声称通过：`docker compose up --build`、
  `tools/lint.sh`（容器内 PHP lint + 三个测试）、`tools/smoke.sh` 权限与配额用例、
  migration 与 Seeder 的真实数据库写入。相关条目保持「进行中」。
- 数据库里已存在的考生 B 数据需重新执行 Seeder（容器重建流程已含 `db:seed --force`）才会清除。
- `python tools/doclink.py` 报 `README.md: docs/api.md` 断链。该问题在本次改动前的
  `HEAD` 上即存在（`docs/api.md` 从未入库），与本次改动无关，未修。

## V0.1-dev.5 - 2026-09-27

### 变更

- 当前版本表面从 `V0.1-dev.4` 升级为 `V0.1-dev.5`，同步更新 `VERSION`、健康检查 API
  版本字段、前端抽屉版本行、README 当前版本行与开发规划当前版本行。历史版本标题未改动。
- 新增生产部署（宝塔 + Docker）配套脚本与文档后的集成验收记录。

### 验证

- `git diff --check`：通过（无空白错误）。
- `python tools/doclink.py`：`checked 23 relative links in 7 files`，`OK`。
- `python tools/phpcheck.py`：`checked=101 files, classes=68, tables=20`，`OK`。
- `python tools/phpcheck_selftest.py`：7 个案例全部 `PASS`，`SELFTEST OK`。
- `python -m unittest discover -s tools/ingest -p "test_*.py" -v`：20/20 通过。
- `npm test --prefix apps/web`：36/36 通过。
- `npm run build --prefix apps/web`：Nuxt 生产构建成功，`Σ Total size: 5.17 MB (1.34 MB gzip)`。

### 已知限制

- 当前主机没有 PHP，未执行 PHP lint 或可执行 PHP 测试。
- Docker/PHP/live-MySQL 相关步骤（`compose up`、`lint.sh`、`smoke.sh`、`deploy.sh`
  线上运行、`docker inspect`、`healthcheck.sh`）在本机不可运行，均已 SKIPPED，未声称成功。
- 生产部署（1.7）与数据导入闭环（1.4）保持 `进行中`：Docker/live 验证证据缺失，
  待目标服务器实测后再标记 `已完成`。

## 文档补充 - 2026-09-26

### 变更

- 记录数据集完整性工作流：工作区只读 `storage/raw/` 副本经 `build_all.py` 生成 8 个数据集与
  `storage/dataset-manifest.json`，API 在 migration/Seeder 前校验摘要与来源清单。
- 明确 F 盘资料只作为只读来源，需复制到被忽略的工作区存储，且不挂载到 Docker。
- 开发规划 1.4「数据导入闭环」保持 `进行中`，等待最终生产集成验证。

### 验证

- `python tools/doclink.py`：`checked 21 relative links in 6 files`，`OK`。
- `python tools/phpcheck.py`：`checked=100 files, classes=67, tables=20`，`OK`。
- `python tools/phpcheck_selftest.py`：7 个案例全部 `PASS`，`SELFTEST OK`。
- `python -m unittest discover -s tools/ingest -p "test_*.py" -v`：20/20 通过。
- `git diff --check`：通过。

### 已知限制

- 当前主机没有 PHP，未执行 PHP lint 或可执行 PHP 测试；按当前环境约束未启动 Docker，
  因此未记录容器负向/正向冒烟结果。

## V0.1-dev.4 - 2026-09-23

### 修复与新增

- 修复 `AuthController`、`StudyController` 中未加括号的 PHP `new Class()->method()` 链式调用语法错误，
  恢复登录、收藏、笔记、进度、统计和后台接口。
- 修复 Docker 权限：WSL2 Ubuntu-24.04 用户 `administrator` 加入 `docker` 组；普通用户可运行
  `docker version`（29.8.1）和 `docker ps`。
- 新增 `PATCH /api/v1/mistakes/items/{id}/action`，登录用户可保存错题重练后的行动建议。
- 错题页新增单选/多选重练、即时判分、原错因对比，并将作答写入 `/study/attempts`。
- `tools/phpcheck.py` 新增未加括号 `new Class()->method()` 风险检查，反向自测扩展为 6 类错误。

### 验证

- `python tools/phpcheck.py`：`checked=99 files, classes=66, tables=20`，`OK`。
- `python tools/phpcheck_selftest.py`：6 类错误用例与 clean tree 全部 `PASS`，`SELFTEST OK`。
- `npm test --prefix apps/web`：36/36 通过。
- `npm run build --prefix apps/web`：Nuxt 生产构建成功，`Σ Total size: 5.17 MB (1.34 MB gzip)`。
- WSL 原生验证副本执行 `docker compose up --build -d` 成功，19 个 migration 与 Seeder 成功；
  `bash tools/smoke.sh` 通过。认证、用户态、后台、搜索、分页和错题 action 接口均验证。

## V0.1-dev.3 - 2026-09-22

本版本把 `F:\2027考研资料\考研政治` 的时政热点、真题分析与个人错题分析
全部接入站点，并补齐站点基础功能：真题回顾、模拟押题、站内搜索、登录注册、
用户学习记录与后台管理。

### 新增

**离线导入器（`tools/ingest/`，只读源目录 -> `storage/dataset/*.json`）**

- `common.py`：Markdown->HTML（tables/fenced_code/sane_lists）、星级 `★`->`S/A/B/C`、
  文本清洗、稳定 slug（ascii 前缀 + sha1 后缀）。
- `build_questions.py`：`真题库v2` -> `papers.json`（42 卷）+ `questions.json`（1479 题）。
- `build_articles.py`：-> `analysis_articles.json`（13 篇）+ `hotspots.json`（3 期）
  + `predictions.json`（6 篇）。真题分析按 `ANALYSIS_CATEGORY` 分「选择题规律 /
  分析题规律 / 会议与周年 / 综合结论 / 数据说明」五类。
- `build_mistakes.py`：-> `mistakes.json`（2 名考生 / 292 题 / 5 份提分手册）。
  解析 `**上次**：我选 **B** ｜ 正确 **D** ｜ 单选` 状态行、`- A．选项 ← 标记`
  选项行、`错题明细.md` 的两种表格（马原式 / 毛中特式）以回填 `action` 与 `errorType`。
- `build_mocks.py`：-> `mocks.json`（1 套 / 38 题）+ `stats.json`（图表数据）。
- `build_all.py`：总入口，打印各数据集条数。

**后端（`apps/api`）**

- 14 个新迁移（迁移总数 19）：`papers`、`questions`、`analysis_articles`、`hotspots`（扩展）、
  `analysis_articles`（扩展 `release`/`priority`）、
  `predictions`、`mistake_students`、`mistake_items`、`mistake_handbooks`、
  `mocks`、`mock_questions`、`users`、`user_tokens`、`study_tables`
  （favorites / notes / attempts / study_progress）。
- 20 个模型、10 个 Service、13 个 Controller、15 个 Resource、3 个中间件。
- 52 个路由注册，统一 `{"data": ...}` 包络与 camelCase 字段：
  - 内容只读：`/papers`、`/papers/{pid}`、`/papers/modules`、`/questions`、
    `/questions/{id}`、`/analysis`、`/hotspots`、`/predictions`、`/mocks`、
    `/stats/overview`。
  - 认证：`/auth/register`、`/auth/login`、`/auth/me`、`/auth/logout`。
  - 用户态：`/study/favorites`、`/study/notes`、`/study/attempts`、
    `/study/progress`、`/study/stats`。
  - 后台：`/admin/overview`、`/admin/users`、`/admin/hotspots`、`/admin/analysis`。
- 令牌认证：`Authorization: Bearer <token>`，库里只存 `sha256(token)`，
  30 天有效期；未登录不拦截（`AuthMiddleware`），由 `RequireAuthMiddleware` /
  `RequireAdminMiddleware` 决定拒绝。
- 5 个 Seeder 读数据集幂等灌库，含管理员 `admin@guanlan.local`。
- 真题 `reveal=0` 时服务端不下发 `answer` 与 `analysis`。

**前端（`apps/web`）**

- 页面：`/papers`（列表 + 详情 + 逐题作答）、`/hotspots`、`/predictions`、
  `/analysis`（列表 + 阅读页）、`/mocks`（在线作答 + 交卷判分）、`/mistakes`
  （考生切换 + 模块/章节筛选 + 错题详情 + 提分手册）、`/search`、`/login`、`/me/*`
  （收藏 / 笔记 / 进度）、`/admin/*`（总览 / 用户 / 热点 / 分析）。
- `composables/useAuth.ts`（token 存 localStorage + `useState` 共享）、
  `composables/useApi.ts`（SSR 走容器 DNS、客户端走 nginx 反代）、
  `utils/quiz.mjs`（判分）、`utils/articles.mjs`（排序/分组/摘要）、
  `utils/search.mjs`（类型标签、结果计数、路由解析）。
- 站内搜索新增聚合端点 `GET /api/v1/search?q=&type=&limit=`：一次检索
  真题 / 试卷 / 分析 / 热点 / 预测 / 模拟 / 错题七类，返回 `items`（含
  `type/title/snippet/url/meta`）、`total` 与 `groups` 计数。
- 真题分析新增「发行版」标记：同一主题的工作稿与定稿都保留，定稿带
  `release=true`，列表排序与卡片徽标都优先展示。

### 修复

- 修复 `MockQuestion::$timestamps` 未声明类型导致的 Fatal error。
- 修复 `ArticleService::hotspots()/hotspotCard()` 查询不存在的 `sort_order` 列
  （`hotspots` 表实际列为 `period`/`priority`/`published_at`）。
- 修复 `AppExceptionHandler` 基类错误：应为
  `Hyperf\ExceptionHandler\ExceptionHandler`，不是
  `Hyperf\HttpServer\Exception\Handler\ExceptionHandler`；签名改为
  `handle(Throwable, $response)` + `setStatus()/setHeader()/setBody()`。
- 修复文章摘要提取：跳过表格行、表格分隔线、引用、标题与列表标记，
  原先摘要会以 `| 项目 | 说明 |` 或 `>` 开头。
- 修复真题分析 slug 冲突：`选择题绝对错误选项规律v2_发行版.md` 与工作稿标题
  相同，slug 相同会被唯一约束合并掉发布稿，现为发行版追加区分后缀。
- 修复 `utils/search.mjs` 的 `resolveHitUrl` 会接受站外链接
  （`https://` / 协议相对 `//`），现只接受站内相对路径。

### 变更

- 版本从 `V0.1-dev.2` 更新为 `V0.1-dev.3`，同步 `VERSION` 与 README。
- 新增 `hotspots` 扩展列（`slug`/`period`/`priority`/`published_at`/`html`/
  `outline`/`source_file`/`word_count`），长文与首页卡片共用一张表：
  卡片行 `slug` 为 NULL，长文行 `slug` 非空。
- `analysis_articles` 新增 `release`（是否定稿）与 `priority`（星级）两列，
  列表按 `release DESC, sort_order ASC` 排序。
- 新增 `tools/phpcheck.py`：离线 PHP 结构检查器，覆盖
  PSR-4 命名空间一致性、括号配平、`extends`/`new`/`::` 类引用可解析性、
  模型 `$casts` 列与迁移列一致、路由到控制器方法存在性。
- 新增 `tools/phpcheck_selftest.py`：反向注入 5 类真实错误，验证检查器本身有效。
- 新增 `tools/verify.ps1` / `tools/verify.sh`：本机离线验证总入口。
- `tools/smoke.sh` 扩展第 7-11 组：统一检索、认证、用户态、后台、详情页 404。

### 验证

离线验证（Windows 本机，无 PHP / 无 Docker）：

```
powershell -NoProfile -ExecutionPolicy Bypass -File tools/verify.ps1

== Python 语法检查（tools）
   OK
== PHP 结构检查（PSR-4 / 模型列 / 路由）
checked=99 files, classes=66, tables=20
   OK
== PHP 检查器反向自测（5 类错误必须被抓到）
PASS  psr4-mismatch          exit=1
PASS  unbalanced-brace       exit=1
PASS  bad-cast-column        exit=1
PASS  route-missing-method   exit=1
PASS  unresolved-class       exit=1
PASS  clean-tree             exit=0

SELFTEST OK
   OK
== 数据集重新生成
papers=42 questions=1479
analysis_articles.json 13
hotspots.json 3
predictions.json 6
students=2 items=292 handbooks=5
mocks 1 [38] [33]
   OK
== 前端测试（node --test）
# tests 32
# pass 32
# fail 0
   OK
全部离线验证通过。
```

- `npm run build`（`apps/web`）：通过，Nuxt 3.21.11 生产构建成功，
  `Σ Total size: 5.16 MB (1.34 MB gzip)`，新增 chunk `search-*.mjs`。
- 数据集规模：`papers.json` 42 卷、`questions.json` 1479 题、
  `analysis_articles.json` 13 篇、`hotspots.json` 3 期、
  `predictions.json` 6 篇、`mistakes.json` 2 考生 / 292 题 / 5 手册、
  `mocks.json` 1 套 38 题（33 题带答案）、`stats.json` 182 项。

### 已知限制

- **Docker 权限已修复**：WSL2 Ubuntu-24.04 用户 `administrator` 加入 `docker` 组并重新启动发行版；
  `docker version` 显示 29.8.1，普通用户可运行 `docker ps`。
- 从工程工作区复制到独立验证目录 `~/guanlan-verify`（排除密钥、缓存、依赖及构建产物）后，
  `docker compose up --build -d` 构建成功，API 日志确认 19 个 migration 与 Seeder 完成；
  数据统计为 analysis 13、hotspots 3、predictions 6、mistake students 2 / items 292 / handbooks 5、papers 42 / questions 1479。
- 容器启动期间 `/api/v1/health`、`/api/v1/search?q=马原`、
  `/api/v1/mistakes/students/A/items?page=2&perPage=20` 均返回 HTTP 200。
- **验证仍未完成**：WSL 发行版在调用结束后自动停止，Docker daemon 随之退出，导致 Windows `localhost:8080` 不可持续访问；
  `tools/smoke.sh` 未完整执行。开发规划 B1 保持进行中。
- 站内搜索用 SQL `LIKE` 全表扫，数据量继续增长需换索引或 Meilisearch。
- `documents` 表（V0.1-dev.2 建立）本期仍无数据，PDF 在线阅读未开始。
- 模拟押题仅 1 套；`船` 目录下的其他模拟卷未纳入。
- 考生 B 的马原批次源文件导出时删掉了错选项，15 条无法判定错因，
  记为 `errorType=未记录`。
- 移动端为响应式降级，未做专属布局。
- `apps/web` 的 peer 依赖冲突仍以 `.npmrc` 的 `legacy-peer-deps` 绕过。

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
