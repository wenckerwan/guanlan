# V0.1-dev.1 学科导航实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** 为观澜 Nuxt 前端增加六大学科资料库、学科详情页、章节/知识点入口、面包屑和移动端导航。

**Architecture:** 使用 `data/subjects.ts` 作为静态领域数据；用 Nuxt 文件路由提供 `/subjects` 和 `/subjects/[slug]`；用共享头部和面包屑组件统一桌面/移动导航；首页只通过 `NuxtLink` 消费这些稳定 slug。

**Tech Stack:** Nuxt 3.21.11、Vue 3.5、TypeScript、lucide-vue-next、Node test runner。

## 全局约束

- 本版本只做纯前端，不新增 Hyperf API 或 MySQL 逻辑。
- 版本字符串更新为 `V0.1-dev.1`。
- CHANGELOG 必须记录实际执行的测试与构建命令。
- 不提交 `node_modules`、`.nuxt`、`.output` 或原始资料。

### 任务 1：建立领域数据和可测试查询工具

**文件：**
- 新建：`apps/web/data/subjects.ts`
- 新建：`apps/web/utils/subjects.mjs`
- 新建：`apps/web/tests/subjects.test.mjs`

- [ ] 写测试：验证六个 slug 唯一、`getSubjectBySlug` 可返回学科、未知 slug 返回 `undefined`，热点映射均指向已知 slug。
- [ ] 运行 `node --test tests/subjects.test.mjs`，先确认失败。
- [ ] 添加六科及章节/知识点种子数据，并实现纯函数查询。
- [ ] 再运行测试，确认通过。

### 任务 2：抽取共享导航和面包屑组件

**文件：**
- 新建：`apps/web/components/SiteHeader.vue`
- 新建：`apps/web/components/Breadcrumbs.vue`
- 修改：`apps/web/assets/css/main.css`

- [ ] 保留现有品牌样式，抽取桌面主导航、移动抽屉和底部导航；所有路由入口使用 `NuxtLink`。
- [ ] 为抽屉增加关闭按钮、遮罩和 `aria-expanded`，移动端不依赖 hover。
- [ ] 面包屑提供首页、资料库、当前学科和返回资料库按钮。
- [ ] 使用现有 CSS token，补充抽屉、详情卡片和焦点态样式。

### 任务 3：创建资料库和学科详情页

**文件：**
- 新建：`apps/web/pages/subjects/index.vue`
- 新建：`apps/web/pages/subjects/[slug].vue`
- 修改：`apps/web/app.vue`

- [ ] 资料库页渲染六张学科卡片并链接到详情页。
- [ ] 详情页从 route params 读取 slug，渲染面包屑、简介、章节卡片和知识点列表；未知 slug 显示返回资料库的空状态。
- [ ] 首页接入共享头部，学科卡片链接到对应 slug，热点条目链接到映射学科并保留章节 query。
- [ ] 修正首页“进入资料库”、移动底部导航和返回入口，移除 `href="#"` 死链接。

### 任务 4：版本记录和回归验证

**文件：**
- 修改：`VERSION`
- 修改：`CHANGELOG.md`
- 修改：`README.md`（如版本说明需要同步）

- [ ] 将版本改为 `V0.1-dev.1`。
- [ ] 按固定格式写入新增内容、验证命令和已知限制。
- [ ] 运行 `npm test`、`npm run build` 和 `git diff --check`。
- [ ] 检查 Git 状态，确认没有缓存和原始资料进入提交。
