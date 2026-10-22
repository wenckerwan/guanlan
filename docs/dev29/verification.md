# dev.29 验证记录

日期：2026-10-22。基线：5ed992d。版本：V0.1-dev.29。

## 本地已执行

- Node 前端回归：95/95 通过，其中史纲仓库 16 项、重连持久化失败及重试 1 项。
- 发布回归：11/11 通过；工作区快照回归：2/2 通过。
- Nuxt 生产构建成功；Python 编译检查通过。
- 独立预览浏览器夹具：AI 完整 SSE、截断、401、兼容回退、历史报告与安全渲染通过；史纲实际离线/上线、401 草稿恢复、同浏览器 A→B→A 切换通过，无页面错误。
- 发布数据集指纹包括 dataset 目录、dataset-manifest.json、import-manifest.json 和 history 目录；内容变化在切换镜像前拒绝。

浏览器使用隔离 API 夹具；发布行为测试使用状态化 Docker 适配器。它们没有调用真实模型、发送邮件或修改生产数据。

## 未执行的检查

- 本机没有 PHP、Docker，PHP 测试、实际数据集 PHP 校验及真实 Docker 演练尚未在本机执行。
- CI 已配置 PHP、Node、构建、浏览器和独立临时 Docker 项目演练；配置存在不代表云端运行通过。
- Git 忽略的密钥、原始材料和备份路径不属于工作区只读快照范围。

## GitHub 与生产边界

用户于 2026-10-22 要求先推送 GitHub，并明确服务器维护期间不部署。本次只提交和推送 GitHub 分支，不连接服务器，不发布镜像或更改生产服务。

详细行为见 history-stability-report.md、release-stability-report.md；独立审查及后续处理见 final-review.md。
