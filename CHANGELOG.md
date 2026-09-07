# 更新记录

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
