# dev.29 验证记录

> 最新交付状态：976f5c9 已合入 GitHub main；生产 ACTIVE 状态、运行镜像、备份、回退镜像、内容摘要和 HTTP 验收已核对通过，见 [2026-10-23 生产记录](deployment-20261023.md)。下方未合入或不连接生产的描述为先前验收阶段记录。

## 2026-10-23 验收更新

验收提交：`976f5c92d38a51c78c58dfafeabe41e49ead63b8`，分支 `fix/security-production-20261016`。下方 2026-10-22 的“未执行”描述保留为历史记录，当前状态以本节和 [验收收尾报告](acceptance-20261023.md) 为准。

- 已读取并核对 [GitHub Actions 39776344928](https://github.com/wenckerwan/guanlan/actions/runs/39776344928)，该提交所有 CI 步骤成功：离线回归、部署配置校验、Nuxt 构建、生产预览浏览器夹具和真实 Docker 镜像切换/回退演练。
- 本机重新执行 `tools/test_stability.py --php <PHP 8.3.36>`：95/95 前端测试、11 个 PHP 测试入口、8 份实际数据集完整性校验、3/3 稳定性入口/快照回归、11/11 发布行为回归通过，退出码 0。
- 本机重新执行 Nuxt 生产构建：退出码 0；仍有依赖 DEP0155 警告。
- 本机基于本次构建重新执行 `node tools/tests/run-dev29-browser.cjs`：AI 完整/截断/回退/401/历史/清洗和史纲离线/重连/401/待同步重放/账号切换全部通过，无页面错误，退出码 0；缺失的指定 Chromium 已补齐。
- CI 的 Docker 演练使用真实容器、合成服务和 SQL，验证成功激活、显式回退、失败自动回退和四个容器不变；不等同于真实 MySQL 恢复或业务 API 集成。
- 远端 main 当前为 `5ed992d5e3a4c1cc6a5e63f5a137779926f7a5ce`；DEV29 提交领先 main 两个提交、落后零个提交，尚未合入。没有连接生产服务器或执行部署。

CI 元数据见 [ci-39776344928.json](ci-39776344928.json)，关键日志见 [ci-39776344928-summary.txt](ci-39776344928-summary.txt)。

## 2026-10-22 历史记录

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
