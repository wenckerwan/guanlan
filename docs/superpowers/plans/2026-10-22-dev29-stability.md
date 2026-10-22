# dev.29 Stability Implementation Plan

> 执行方式：在当前会话内分批实施、验证；用户已选择 dev.29 的既定范围。

**Goal:** 修复已复现的AI报告截断与史纲离线恢复问题，提供可重复安全回归和镜像发布/回退。
**Architecture:** 保留Nuxt/Hyperf结构，提取SSE读取器用于可执行测试；史纲用账号分区与远端基线计算待同步增量；发布使用已构建镜像、独立备份目录和可审查状态文件，不在宿主重建或恢复数据库。
**Tech Stack:** Vue/Nuxt、Node test、PHP CLI、Python unittest、Docker Compose。

## Global Constraints

- 保留 main/apps/web 基线、玻璃主题、现有导航与路由；当前HEAD等于已发布main基线。
- 不调用真实付费模型，不发送真实邮件，不改现有用户数据。
- 生产发布与本地/隔离验证分别记录，未执行不宣称完成。

## Tasks

- [x] report-stream.test.mjs先失败；实现utils/report-stream.mjs，接入analyze/[code].vue。断流、空完成、错误不自动保存，不因401/429/供应商失败重调模型；只有404/405/501允许兼容回退。
- [x] history-study.test.mjs复现离线编辑/删除丢失及存储失败伪装为空；修复guanlan-study.ts增量重放与持久化失败传播。
- [x] HistoryExplorer.vue重连事件恢复及同步状态；实际浏览器验证离线、恢复、401、账号切换。
- [x] tools/deploy/release.py及test_release.py：prepare备份旧镜像/数据库/配置摘要，activate仅切镜像，失败rollback旧镜像；不运行git reset/clean/build/数据库恢复。配置漂移和缺失镜像拒绝发布。
- [x] tools/test_stability.py作为只读数据回归入口；CI安装锁定依赖后运行PHP、Node、发布演练与构建。
- [x] 本地实际页面验证AI SSE完整、安全渲染、历史读取、截断与兼容回退（API桩，不冒充真实模型端到端）。
- [x] 完整回归、构建、独立审查；VERSION与CHANGELOG记录真实边界。
