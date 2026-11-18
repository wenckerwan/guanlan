# 观澜 Vue 全站视觉重构开发方案

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. 本文是阶段实施方案；每阶段动工前补齐该阶段的具体组件改动与视觉稿，不凭未确认的组件接口直接批量改页面。

**Goal:** 保留观澜品牌、Nuxt/Vue 技术栈和全部业务，以 Lenso 的分组、层级、浮动玻璃材质和交互原则统一全站视觉。

**Architecture:** 先核对生产基线，再建立语义 Token 和 Vue 展示组件，以首页、文章、练习三类代表页面定型，随后按页面族迁移。主站和独立嵌入应用分别处理样式边界，最终以预览环境完成验收，按现有发布流程上线。

**Tech Stack:** Nuxt 3.21.11、Vue 3.5.12、CSS 自定义属性、lucide-vue-next、现有 Node 测试与浏览器验证；不引入 React/StyleX/Lenso React 运行时。

## 1. 范围与约束

- 当前分支：`feature/lenso-visual-refactor-20261117`；起点为本地 `origin/main` 引用 `a312289`。尚未证明此引用与生产部署一致。
- 项目入口为 `apps/web`；继续加载 `main.css`、`glass.css`，不使用已归档原型作为开发基线。
- 保留 Logo、观澜红、现有模块名称、导航、路由、API、权限、判分和离线同步；本次只改展示和必要的交互可访问性。
- 当前 Node 基线测试 109/109 通过。它证明相关业务测试通过，不代表视觉、SSR、生产构建或当前线上版本已验证。
- 不部署、不合并、不改线上配置；本方案的开发完成物是可预览、可审核的分支。
- 保留仓库现有未跟踪文件，显式选择每次提交文件，不使用 `git add .`。
- “全站”包含主站、登录/账户、管理端、史纲和马原。独立子应用先核实技术和发布边界，再在其原有技术栈中实现相同设计原则。

## 2. 设计方向

| 设计项 | 决策 |
| --- | --- |
| 品牌 | 保留观澜红及 Logo；不照搬 Lenso 默认蓝 |
| 页面 | 灰白/深色中性底、清晰分组、减少套娃卡片 |
| 内容 | 文章、题干、表格、编辑器采用稳定实体表面 |
| 玻璃 | 导航、浮动工具栏、菜单、弹层按需要使用；CSS 模糊和渐变近似，不承诺复制 Lenso 折射引擎 |
| 排版 | 中文可读性优先；正文建议 16–18px、约 1.7 行高；具体值以代表页面验证为准 |
| 控件 | 同类控件统一尺寸；主要触控目标至少 44px，密集表格单独校验 |
| 状态 | hover、焦点、选中、错误、禁用分别表达；不靠单一颜色传递状态 |
| 动画 | 只用于状态和空间关系反馈；编辑、阅读区域稳定，支持减少动画 |
| 降级 | 无 backdrop-filter 时使用实体背景；不实现复杂融合玻璃、光学场或像素级原生仿真 |

## 3. 文件责任与组件边界

| 位置 | 责任 |
| --- | --- |
| `apps/web/assets/css/main.css` | 通用布局、排版、控件、响应式结构；逐步移除重复规则 |
| `apps/web/assets/css/glass.css` | 现有品牌主题入口；集中维护颜色、材质、阴影、圆角及主题变量 |
| `apps/web/nuxt.config.ts` | 继续按 main → glass 顺序加载；确认构建配置，不为视觉重构改接口配置 |
| `apps/web/components/ui/`（新增） | 经重复需求证实后提取展示组件，例如 GlButton、GlField、GlDialog、GlEmptyState；不抽象业务数据 |
| `apps/web/components/SiteHeader.vue`、`SiteFooter.vue` | 全局导航与页脚，保持路由和权限条件 |
| `apps/web/components/ArticleGrid.vue`、`ArticleReader.vue`、`Breadcrumbs.vue`、`PaginationControls.vue` | 跨内容页面共用展示 |
| `apps/web/components/LoginGateModal.vue`、`CommentSection.vue` | 登录门控与评论状态，保留认证与评论开关 |
| `apps/web/layouts/admin.vue`、`components/Admin*.vue` | 管理端布局、列表、编辑器、历史与批量操作 |
| `apps/web/components/history/ui/` | 史纲的浏览、阅读、练习与证据展示；不得破坏数据适配层 |
| `vendor/mayuan/apps/web/` | 马原独立应用；先查本目录指引、构建方式和嵌入边界，再制定该子项目实施清单 |
| `apps/web/composables/`、`components/history/core/`、`adapters/` | 业务层，原则上不改；如有必要，单独说明原因与回归证据 |

变量采用 `--gl-*` 命名，分为背景/表面、文字、边框、品牌/状态、间距、尺寸、圆角、阴影、动画。旧 `--paper`、`--ink`、`--brand` 等先映射到新变量，防止一次性替换破坏旧页面。不在旧样式文件尾部无限追加覆盖；每批迁移清理对应规则并验证选择器影响范围。

## 4. 实施阶段

### P0：核对基线与建立页面清单（2–3 工作日）

- [ ] 记录分支、commit、工作区状态；只读刷新远程引用并比较生产部署记录、镜像/源码版本；存在差异时先形成差异清单，不自动合并生产热修。
- [ ] 清点 `apps/web/pages/`、史纲、马原全部路由；逐项记录入口、权限、数据依赖和发布边界。
- [ ] 记录现有主题实现；不能假设已存在完整深色模式。
- [ ] 在本地或测试环境采集首页、文章、练习、搜索、个人页、管理表格、编辑器、史纲和马原基线截图。
- [ ] 建立截图矩阵：390、768、1440 CSS px；亮/暗目标主题；加载、空、错误、有数据状态。
- [ ] 新建 `docs/superpowers/reviews/2026-11-17-lenso-baseline.md`，保存版本、页面清单和截图位置。

**完成条件：** 明确开发所依据的代码与生产差异；全部页面有归属，没有遗漏独立应用。

### P1：设计规范与三类代表页面定型（4–6 工作日）

- [ ] 使用真实中文内容制作首页、文章详情、题目练习三类页面的桌面/手机效果，保留现有信息与操作。
- [ ] 同时确定品牌红在浅色/深色的表现、内容宽度、字号、分组间距、卡片/控件圆角和浮动材质。
- [ ] 为按钮、输入、筛选、标签、Tabs、弹层、分页、表格、空/错状态确定视觉和交互规范。
- [ ] 提供同一浏览器中的实际预览供用户审阅；将设计决策写入 `docs/design/lenso-visual-system.md`。

**完成条件：** 用户能审阅三个真实页面及亮暗/手机效果，方向明确后再扩展。此时不批量重排其他页面。

### P2：Token、共享控件与全局壳层（5–7 工作日）

- [ ] 修改 `main.css`、`glass.css`，建立新变量与兼容映射，保持旧页面可用。
- [ ] 按代表页面实际重复需求提取 Vue 展示组件；按钮保留原生 type/disabled，输入保留 v-model、label，弹层实现 Escape、焦点约束/恢复与滚动锁定。
- [ ] 改造 SiteHeader、SiteFooter、Breadcrumbs、PaginationControls、LoginGateModal；保留导航及权限逻辑。
- [ ] 核查 Header 在长用户名、登录/游客、窄屏菜单和浏览器返回时的表现。
- [ ] 新行为（例如弹层焦点处理）先写可复现的交互测试；纯颜色/间距使用截图检查，不用 CSS 字符串断言代替视觉验证。

**完成条件：** 全局壳层、共享控件可用，未迁移页面无明显退化。

### P3：内容发现与阅读页面（5–7 工作日）

- [ ] 先完成 `pages/index.vue`、ArticleGrid、ArticleReader，再按页面族处理 subjects、hotspots、predictions、analysis。
- [ ] 重做 `pages/search.vue`：保留 query 参数、筛选、分页和浏览器历史恢复。
- [ ] 统一文章目录、元信息、引用、登录锁定和评论，不改变清洗/安全渲染链路。
- [ ] 检查超长标题、富文本、表格、公式/图片、内容缺失和请求失败。

**完成条件：** 首页 → 搜索/分类 → 详情 → 返回的主要浏览路径通过桌面和手机验证。

### P4：学习与账户页面（6–8 工作日）

- [ ] 逐组迁移 mistakes（列表/详情/复习/分析）、practice/current-affairs、papers、mocks。
- [ ] 保留题干、选项、作答、判分、解析和下一题状态；避免视效导致选项误触或工具栏遮挡。
- [ ] 迁移 login、me/index、notes、favorites、progress；保留账户切换和待同步/冲突/失败提示。
- [ ] 回归错题分析流式输出、断流失败、登录失效、离线保存及刷新恢复。

**完成条件：** 登录 → 作答 → 查看结果 → 收藏/笔记 → 复习全流程通过；原有关键业务测试保持通过。

### P5：管理端与独立应用（7–10 工作日）

- [ ] 先迁移 admin layout 和共享列表/分页/状态组件，再迁移全部 admin 页面。
- [ ] 处理 AdminArticleEditor、AdminQuestionEditor、历史、趋势及批量操作；检查长表格、校验错误和危险操作。
- [ ] 单独处理史纲 HistoryExplorer、HistoryReader、PracticePanel、EvidencePanel、TimelineCanvas 及其 CSS；保持深链接和宿主返回行为。
- [ ] 对马原独立制定文件级清单，在原有技术栈中同步品牌/排版/控件规则，不强制与主站共用 CSS 或共享运行时。
- [ ] 验证主站到两类子应用的导航、登录、嵌入尺寸与样式隔离。

**完成条件：** 页面清单全覆盖，管理端和子应用没有保留一套明显冲突的视觉体系。

### P6：整体验收与发布准备（4–6 工作日）

- [ ] 固定浏览器版本、字体、测试数据、视口和截图时机，产出逐页对照截图，人工评审差异，不盲目更新截图基准。
- [ ] 覆盖 Chrome/Edge、Firefox 和 WebKit 代表页面；实际 Safari/iPhone 未测时明确标记缺口。
- [ ] 测试 200% 缩放、键盘操作、对话框焦点、亮暗对比、减少动画、玻璃不支持时降级。
- [ ] 同环境对比主要页面加载、滚动和交互；禁止全屏多层 backdrop、每帧截图和常驻动画。性能明显退化时优先减少材质层。
- [ ] 运行 Node 测试和生产构建，使用独立测试账号/数据进行预览验收；预览环境不得写生产数据。
- [ ] 创建 `docs/superpowers/reviews/2026-11-17-lenso-acceptance.md`，记录截图、命令、结果、限制和发布/回滚步骤。

**完成条件：** 所有页面验收记录齐全，关键流程通过，无已知严重阻塞；未验证项如实列出。

## 5. 每批改动的检查与提交

在 `apps/web` 执行：

```powershell
npm test
npm run build
```

预期：测试退出码 0，Nuxt production build 退出码 0。构建通过不代表 TypeScript 全量检查通过：当前 `typescript.typeCheck` 为 false，如需类型检查先确认现有工具并单独记录结果。

每批先跑受影响的具体测试，再在阶段结束跑完整测试和构建；只有新增改动、失败或未解决问题时重复扩大验证。浏览器操作使用当前环境允许的浏览器工具，不在公共文档写入测试账号凭据。

独立提交建议：`feat(ui): add semantic theme tokens`、`feat(ui): refresh navigation shell`、`feat(ui): refresh content pages`、`feat(ui): refresh study workflows`、`feat(ui): refresh admin surfaces`、`feat(ui): align embedded applications`。每次只添加明确文件，记录可供回滚的 commit。

## 6. 进度与发布策略

- 第一检查点：P0–P1，确认生产差异和真实页面方向。
- 第二检查点：P2–P3，可完整体验新版壳层和阅读路径。
- 第三检查点：P4–P5，学习、管理和子应用覆盖。
- 最终检查点：P6，提交预览地址、测试与视觉验收报告，再决定合并和生产发布。
- 粗估合计 33–47 个工作日（约 7–10 周，另留约 20% 缓冲）；它是单人完整工作投入估算，不是 AI 执行时长承诺。马原、历史数据和浏览器差异可能增加工作量，P0 后重新估算。
- 首个可审阅版本目标为首页、文章、练习和全局壳层，不等全站完成才展示。
- 本次不包含数据库/接口迁移。发布前保留上一版本代码、前端镜像和现有环境配置；失败时恢复旧镜像或旧前端产物，按现有流程验证。不能用硬重置或删除未知文件代替回滚。

## 7. 执行方式

默认在当前聊天按阶段执行，阶段间展示可审阅结果；尚未开始实施。本方案不自动授权生产部署。如需并行代理，由用户明确选择后再启用。
