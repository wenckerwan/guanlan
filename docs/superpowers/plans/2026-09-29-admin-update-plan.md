# 后台更新开发规划（Admin Backoffice）

> 日期：2026-09-29
> 对应主计划条目：[development-plan.md](../../development-plan.md) 1.6（后台内容管理补齐）、
> 阶段 2（资料阅读后台部分）、阶段 4（安全加固后台部分）。
> 本文件是 1.6 的落地细化；完成后按主计划第七节流程收尾。

## 零、现状盘点（2026-09-29）

### 已有能力

| 能力 | 位置 | 说明 |
|---|---|---|
| 内容计数总览 | `AdminService::overview` | 10 张表行数 + 最近 5 用户 + 今日新增 |
| 用户管理 | `users` / `updateUser` | 关键词搜索（limit 50）、改 role / status / mistake_code（撞号 422） |
| 做题记录 | `attempts` | 最近 limit 条（≤500），无筛选 |
| 错题总览 | `mistakeOverview` | 只有计数，无内容 |
| 时政热点 CRUD | `hotspots` 4 个路由 | 全量返回（limit 100），无分页无状态筛选 |
| 真题分析 CRUD | `analysis` 4 个路由 | 同上 |
| 前端后台 | `apps/web/pages/admin/` | 5 页：index / overview / users / hotspots / analysis |

### 主要缺口

1. **1.6 完成标准未达成**：试卷、预测、模拟押题、错题内容后台不可查看；无发布/隐藏
   状态控制（`hotspots` / `analysis_articles` 表连 `status` 列都没有）。
2. **错题内容管理断头**：`MistakeProfileService::replace()` 已实现 Markdown 上传渲染，
   但没有任何 API 路由和后台页面调用它——考生详情页永远显示默认占位文案。
3. **复习系统数据后台不可见**：V0.1-dev.6/7 新增的 `mistake_reviews`（复习状态、连胜、
   正确率）没有任何后台视图。
4. **双轨内容无对账入口**：papers/questions/mocks/predictions 由数据集 Seeder 写入，
   后台既看不到单条内容，也没有「数据集版本 / 重导入」入口。
5. **用户管理粗放**：无分页、无 role/status 筛选、无注册趋势。
6. **无审计**：管理员写操作（改用户、删热点）不落日志；无注册限流。
7. **AI 配置硬编码**：`AIAnalysisService::getDefaultConfig()` 写死在代码里，后台不可调。

## 一、优先级原则

沿用主计划：能独立验证的先做；写操作一律 `RequireAdminMiddleware`（路由组已有，新增
路由必须挂同一组）；数据集驱动的内容本阶段**只读**，不引入后台编辑（避免和 Seeder 对账
冲突——要改内容走 `tools/ingest/` 重新生成）。

## 二、阶段 A：内容管理补齐（V0.1-dev.8）

分支：`feature/admin-content-mgmt`。目标：走完 development-plan 1.6 的完成标准。

### A1. 内容状态字段（发布/隐藏）

| 项 | 内容 |
|---|---|
| 迁移 | `hotspots`、`analysis_articles` 各加 `status`（`published`/`hidden`，默认 `published`） |
| API | 后台 create/update 接受 `status`；前台文章列表/详情对 `hidden` 返回 404（走同一查询构造，搜索同步过滤） |
| 完成标准 | ① 迁移可重跑；② 隐藏后前台列表、详情、搜索三处都不可见；③ 有 PHP 测试覆盖状态过滤 |

### A2. 只读内容查看页

| 项 | 内容 |
|---|---|
| API | `GET /admin/papers`（卷 + 题数）、`GET /admin/papers/{pid}/questions`（分页 ≤100）、`GET /admin/mocks`、`GET /admin/predictions`，全部分页 |
| 前端 | `admin/papers.vue`、`admin/mocks.vue`、`admin/predictions.vue`：列表 + 详情抽屉，只读 |
| 完成标准 | ① 42 卷 / 1479 题可分页浏览；② 接口在 `RequireAdminMiddleware` 组内；③ 冒烟脚本补 200/401 用例 |

### A3. 后台总览增强

| 项 | 内容 |
|---|---|
| API | `overview` 增加注册趋势（近 14 天每日新增）、`mistake_reviews` 计数 |
| 完成标准 | ① 前端总览页渲染趋势条；② `node --test` 覆盖日期序列纯函数 |

阶段 A 完成即把 development-plan 1.6 标 `已完成`（① 试卷/预测/错题可查看由 A2+阶段 B 覆盖；
② 状态调整 A1；③ 写操作 admin 校验已在路由组内，补断言测试）。

## 三、阶段 B：错题后台闭环（V0.1-dev.9）

分支：`feature/admin-mistake-console`。目标：管理员能完整运维错题板块。

### B1. 错题内容管理

| 项 | 内容 |
|---|---|
| API | `GET /admin/mistakes/students`（全量考生含未绑定）、`GET /admin/mistakes/students/{code}/items`（分页）、`GET/PUT /admin/mistakes/students/{code}/profile`（Markdown 上传，落 `MistakeProfileService::replace()`，记录 `source_file` 与 `updated_by`） |
| 前端 | `admin/mistakes.vue`：考生列表 → 条目浏览 → Markdown 编辑器（上传 .md 或直接粘贴，预览渲染 HTML） |
| 完成标准 | ① 上传后考生详情页立即显示新内容；② 非管理员 403；③ profile 更新写入 `updated_by`，可在响应中看到 |

### B2. 复习数据看板

| 项 | 内容 |
|---|---|
| API | `GET /admin/mistakes/review-stats`：按考生汇总 new/reviewing/mastered/snoozed、平均正确率、逾期数 |
| 前端 | B1 页面内嵌看板区块 |
| 完成标准 | ① 数字与考生端 `review-summary` 对账一致（同一账号交叉验证）；② 汇总用一条 group by 查询，不逐用户循环 |

### B3. 错题条目维护

| 项 | 内容 |
|---|---|
| API | `PATCH /admin/mistakes/items/{id}`：可改 `action`（管理员改全局字段的正式通道，替代现在绕过 `updateAction` 的做法）、`error_type`、`module` |
| 完成标准 | ① 修改后考生端立即可见；② 冒烟覆盖 200/403/404 |

## 四、阶段 C：用户与安全（V0.1-dev.10）

分支：`feature/admin-users-hardening`。对应主计划阶段 4 的后台部分。

| 项 | 完成标准 |
|---|---|
| 用户列表分页筛选 | `page`/`perPage`(≤100)/`role`/`status` 参数；URL 同步；分页控件复用 |
| 注册限流 | 同 IP 注册 5 次/小时（Redis 计数），超限 429；有测试 |
| 管理员审计日志 | 新表 `admin_audit_logs`（admin_id/action/target_type/target_id/detail/created_at）；用户改绑、内容增删改、错题条目维护全部落日志；`GET /admin/audit-logs` 可查 |
| 会话管理 | token 过期时间从 30 天缩到 7 天 + 滑动续期；`updateUser` 禁止管理员自降 role |
| AI 配置可调 | `admin_settings` 表（键值），`getAIConfig` 的 defaultConfig 改读配置，后台可编辑 |

## 五、阶段 D：资料文档管理（V0.1-beta.1，主计划阶段 2 的后台部分）

分支：`feature/admin-documents`。依赖 `documents` 表（已建，空）与导入器。

| 项 | 完成标准 |
|---|---|
| 导入器 | manifest → `documents`，幂等可重跑，记录 `file_path` + sha256 |
| 后台列表/详情 | 分类、年份、来源筛选；在线状态可见 |
| 上传/删除 | 管理员可上传 PDF（≤50MB，白名单 .pdf）与删除；删除仅隐藏不物理删 |
| 权限 | 普通用户只读；未登录不可下载原始文件 |

## 六、阶段 E：运维工具（V0.2，主计划阶段 4 其余部分）

| 项 | 完成标准 |
|---|---|
| 数据集对账页 | 后台展示 `dataset-manifest` 校验结果与各数据集条数/sha256；提供触发重导入的说明（执行命令，不开放网页重导入——避免误触写库） |
| 备份恢复 | MySQL dump 定时 + 恢复演练记录；后台显示最近备份时间 |
| 健康检查增强 | `/api/v1/health` 增加版本号与迁移状态；后台总览显示 |

## 七、不做的事（明确排除）

- **不引入富文本编辑器**：主计划 1.6 备注明确只做状态与元数据；热点/分析正文继续走
  Markdown → HTML 的现有渲染链。
- **不在后台直接编辑数据集内容**（papers/questions/mocks/predictions）：改动必须走
  `tools/ingest/` 重新生成，否则下次 Seeder 会覆盖且校验失败。
- **不开放网页端重导入按钮**：写库操作只留命令行通道。

## 八、里程碑与依赖

```
阶段 A (dev.8)  ──→ 阶段 B (dev.9)  ──→ 阶段 C (dev.10) ──→ 阶段 E (V0.2)
                        │
                        └──→ 阶段 D (beta.1，可并行，依赖文档导入器)
```

- 阶段 A 无外部依赖，可立即开工；A1 的迁移先行。
- 阶段 B 复用阶段 A 的分页/状态模式。
- 阶段 C 的审计日志建议在 B3 之前至少落表，否则 B 的写操作没日志可补。
- 部署侧前置：`composer require hyperf/guzzle`（AI 配置管理联调需要）、
  迁移 `2026_09_29_000001` 与阶段 A 迁移一起执行。

## 九、每阶段收尾固定清单

沿用主计划第七节：认领 → 建分支 → 写测试 → `tools/verify.ps1` → 容器 `lint.sh` +
`smoke.sh` → 升 `VERSION` → CHANGELOG 粘实际输出 → `git diff --check` → 提交
（`feat: ... for V0.1-dev.8`）→ 合并打标。

阶段 A 的冒烟新增用例：隐藏内容 404、admin 只读接口 401/403 矩阵。
阶段 B 的冒烟新增用例：profile 上传后 GET 回读一致、非管理员 403。
阶段 C 的冒烟新增用例：限流 429、审计日志落库断言。
