# 错题 AI 功能移除报告

## 范围与协作

先读取 `AGENTS.md`；仅使用现有 `apps/web` 基线，不修改玻璃主题样式加载。共享工作区当前处于 `feature/shizheng-20261103`，未切换分支或覆盖其他成员改动。

初始删除范围已向主代理报告，专用 AI 服务、工具、测试及 AI 日额度字段清理由主代理追加授权。认证与安全相关文件由其他成员负责，本任务未修改 useAuth、useApi、认证测试、AuthService、安全响应头、nginx 或 nuxt.config。

## 已完成

- 删除错题 AI 专页 `apps/web/pages/mistakes/analyze/[code].vue`（包含 API Key 配置、连接测试、模型选择、上传后 AI 抽取/分析、流式生成与保存报告）。
- 从 `apps/web/pages/mistakes/[code].vue` 移除 AI 分析按钮、AI 历史报告查询/切换/删除呈现及专用样式。
- 从 `apps/api/config/routes.php` 删除 9 条 AI 专用路由：生成、流式生成、连接测试、对话测试、报告增查删、配置模板。
- 从 `apps/api/src/Controller/MistakeController.php` 删除 AI 服务解析、AI 导入辅助、生成、流式生成、测试、AI 报告操作、配置模板方法及仅供 AI 使用的 imports。
- 删除无人使用的 `apps/api/src/Service/AIAnalysisService.php`，前端专用 `report-stream.mjs` / `report-markdown.mjs`，以及对应旧专用测试/手工调用脚本。
- 清除 `apps/api/src/Support/UserGroup.php` 的 AI 日额度，保留用户组和 `features()` 返回结构。`types/api.ts` 的对应字段由 auth-hardening 成员协调删除。
- 新增 `apps/web/plugins/remove-legacy-ai-config.client.ts`：所有客户端启动时仅执行 `localStorage.removeItem('ai_analysis_config')`；不读取/解析旧密钥、不清空其他缓存、不依赖登录；浏览器拒绝存储访问时不阻塞启动。
- 新增 `apps/web/tests/mistake-ai-removal.test.mjs`，同时调整混合 `MistakeImportAccessTest.php`，删除已失效 AI 用例但保留普通导入所有权测试。

## 明确保留

普通错题列表、详情、收藏、删除、复习、行动建议、提分手册、管理员人工上传报告（detail / MistakeProfile）及其路由均保留。未修改数据库、未执行迁移或清理 SQL。历史 AI 报告模型、建表迁移及现有数据保留，但业务页面和接口不再暴露 AI 报告功能。

## 验证结果

- `node --test tests/mistake-ai-removal.test.mjs`：8/8 通过。覆盖专页/服务/工具真实删除、控制器/路由删除、普通错题与人工报告保留、历史模型/迁移保留、仅清理指定旧 key、重复启动幂等、畸形旧数据、受限存储、额度移除。
- `git diff --check`：通过。
- 全量前端测试首次执行：109 项中 107 通过，2 项失败均在正在并行迁移的 `practice-auth-integration.test.mjs`（第 63 行计数预期 1 实际 0；第 88 行读取 undefined.conflict）。已通知 auth-hardening 及主代理，由认证任务完成后重跑，不能据此宣称全量通过。
- 已扫描 apps/web 页面、组件、plugin、类型：除清理插件保留旧 key 名外，不再存在错题 AI、API Key、AI 日额度入口或文案；apps/api src/config/tests 不再引用 AIAnalysisService、AI 路由或 AI 日额度字段。
- PHP 验证未运行：本机 `php: command not found`；Docker CLI 存在，但 daemon 未启动，连接 `dockerDesktopLinuxEngine` 报管道不存在。需在有 PHP 的 CI/既有运行环境补跑 PHP lint 和普通导入权限测试。

## 遗留与发布注意

- `AiOutboundPolicy`、`SafeAiHttpClient` 及其安全测试按主代理要求保留；它们没有业务 AI 路由或调用入口，不为删除业务功能牵连已有安全测试。
- `MistakeService` 的通用 Markdown 导入/解析能力保留，不改共享服务或历史数据。
- 历史文档及归档工作树不作为运行入口，也未批量改写。
- 本任务没有执行部署、生产 API 冒烟或浏览器端验证。新前端构建部署后，访问该站点的旧浏览器才会触发本地旧 key 清理；离线客户端/不同 origin 的存储不能由当前站点远程删除。
- 仍需主代理完成整体认证变更后的全量测试与构建，并在部署环境确认旧 AI URL/端点失效及普通人工报告仍可访问。
