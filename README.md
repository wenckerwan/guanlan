# 马原知识宇宙 V2

独立的马原交互学习平台：Nuxt/Vue 前端、PHP 后端、服务端学习记录和内容维护后台。原 V1 单文件版仍在上一级目录。

## 本机启动

要求 Node.js 22 或更高版本；PHP 8.3 并启用 PDO SQLite。本次已将官方 Windows PHP 放在工程上一级 `.tools/php/`，启动器优先使用它；其他机器可安装 PHP 或设置 `PHP_BINARY`。

首次安装前端依赖：

```powershell
cd "D:\code_files\考研政治工程文件\马原知识宇宙\v2\apps\web"
npm install
```

启动：双击 `启动V2.cmd`，或在 `v2` 目录执行：

```powershell
npm run dev
```

浏览器访问 `http://127.0.0.1:4179`；PHP API 监听本机 `127.0.0.1:8086`。Ctrl+C 停止前后端。本启动器显式选择开发模式，拒绝覆盖正式身份模式。

开发模式提供「学习者」「另一位学习者」「管理员」模拟身份；界面明确标注。数据按不同身份保存在本机数据库，刷新与服务重启不会清空。它们不是观澜正式账号。

## 功能与内容

- 知识地图、知识星球、章节与概念聚焦、关系解释和搜索。
- 概念辨析、三个关系实验、主动回忆、原创练习及复习记录。
- 自评、浏览、回忆和服务端练习统计分别记录。
- 个人收藏、笔记、备份导出、预览确认导入和账号数据隔离。
- 管理员内容草稿、发布版本、回退与引用校验。

初始内容：101 个概念、126 条关系、19 组辨析和 30 道原创单选题。内容来源与核验边界继承 V1 的 `../docs/content-report.md`，没有将原创题标为真题，也没有声称涵盖全部考研考点。

## 开发与验证

```powershell
npm test
npm run build
```

构建产物在 `apps/web/.output`。构建前端不等于部署后端；普通的 HTML 双击方式不适用于 V2。

项目结构：

| 目录 | 职责 |
|---|---|
| `apps/web` | Nuxt/Vue 学习界面与交互 |
| `apps/api` | PHP API、业务校验、存储、身份及同步适配 |
| `content/seed.json` | 初始知识内容 |
| `tests` | 后端、HTTP 集成与启动器测试 |
| `scripts` | 本机启动、构建和统一测试 |
| `docs` | 设计、观澜对接与验收说明 |

## 观澜接入

观澜工程未被改动。正式登录、登录交接及摘要接口须按宿主需求文档适配后联调；当前不能把模拟身份或本地学习记录称为已同步观澜。

宿主适配需求：`D:\code_files\观澜｜考研政治知识库 - 副本\guanlan\docs\mayuan-compatibility-requirements.md`。项目侧说明见 `docs/guanlan-integration.md`。

本机验证使用 PHP 开发服务器和 SQLite。Hyperf/Swoole 与 MySQL 为生产部署目标，本机没有 Docker，容器运行和真实观澜联调需在相应环境验收。

具体测试、文件选择器权限及依赖公告边界见 [验收记录](docs/verification.md)，独立审查见 [复核报告](docs/review-report.md)，部署目标见 [部署说明](docs/deployment.md)。
