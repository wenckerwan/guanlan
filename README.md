# 观澜 Guanlan｜考研政治知识库

把知识梳理、真题练习、时政阅读与错题复盘放在同一个学习工作台。观澜面向考研政治复习，以结构化资料为基础，结合马原概念关系图和近现代史时间轴，帮助学习者从单个考点走向知识之间的联系。

**在线使用：[guanlan.wencker.top](https://guanlan.wencker.top)**

[马原知识探索](https://guanlan.wencker.top/mayuan/) · [史纲时间轴](https://guanlan.wencker.top/history/) · [真题回顾](https://guanlan.wencker.top/papers) · [时政热点](https://guanlan.wencker.top/hotspots)

## 功能概览

| 模块 | 可以做什么 |
| --- | --- |
| 学科导航 | 按学科进入复习内容，串联知识、文章与练习入口 |
| 真题与分析 | 按试卷浏览历年题目、隐藏答案练习，阅读命题分析与统计 |
| 时政与预测 | 阅读按期整理的时政长文、考点预测与模拟卷 |
| 错题复盘 | 管理个人错题，查看分析、重练并记录复习行动 |
| 马原知识探索 | 在 3D 概念关系图与知识星空中探索章节、搜索概念、聚焦相邻关系，阅读定义、条件与误区；支持 2D 图谱回退 |
| 史纲时间轴 | 探索 1839—2026 年历史事件，进行事件对照与同期观察，查看资料出处 |
| 个人学习空间 | 登录后管理主站收藏、笔记、做题记录和阅读进度 |
| 搜索与管理 | 检索主站内容；管理员维护内容、用户、评论及审计记录 |

马原当前内容包覆盖 **7 个模块、101 个概念、126 条编写关系、19 组辨析与 30 道原创单选题**。图谱连线来自收录的关系数据，不代表任意两个概念之间都存在已核验的联系，也不承诺覆盖全部考研考点。

> 学习状态有不同存储边界：主站账号数据由服务端管理；当前马原前端免登录使用，浏览、自评、回忆和练习记录保存在当前浏览器的 `localStorage`，不自动同步到观澜账号或其他设备。可通过马原页面导出、导入备份；清除浏览器数据会清空本地记录。

## 技术架构

| 层次 | 实现 |
| --- | --- |
| 主站前端 | Nuxt 3、Vue 3、Lucide 图标 |
| 马原子应用 | Nuxt 4、Vue 3、Three.js；以 `/mayuan/` 挂载的客户端应用 |
| 史纲模块 | 嵌入主站的前端组件，通过观澜 API 读取事件与学习数据 |
| API | PHP 8.3、Hyperf 3.1、Swoole；主站 `/api/v1/`，马原 `/api/v2/` |
| 存储 | MySQL 8.4、Redis 7；马原生产后端使用独立 `mayuan` schema |
| 内容工具 | Python 导入、清洗与校验；Node.js 时政推送工具 |
| 部署 | Docker Compose、Nginx 网关；外层反向代理负责域名与 HTTPS |

生产请求入口由 [网关配置](docker/nginx/production.conf) 分流：

```text
浏览器 → HTTPS 反向代理 → 127.0.0.1:8080 → gateway
                                          ├─ /          → web（主站与史纲）
                                          ├─ /api/v1/   → api
                                          ├─ /mayuan/   → mayuan-web
                                          └─ /api/v2/   → mayuan-api

api / mayuan-api → MySQL（主站与马原分库）
api             → Redis
```

生产编排只将网关绑定到回环地址；MySQL、Redis 与 API 不直接暴露公网。马原后端对接能力和当前前端本地学习模式应分别理解，不能仅凭部署了后端就认为学习记录已云端同步。

## 仓库结构

```text
guanlan/
├── apps/
│   ├── web/                   # 主站 Nuxt 应用，含史纲组件
│   └── api/                   # Hyperf API、业务服务与数据入库
├── vendor/mayuan/             # 马原知识宇宙子应用（subtree）
├── storage/                  # 原始资料、结构化数据与校验清单
├── docker/                   # Nginx 与容器配置
├── tools/
│   ├── ingest/               # 离线导入与数据集生成
│   └── deploy/               # 部署、更新、健康检查与备份恢复
├── docs/                     # 部署、内容维护、设计与验收文档
├── docker-compose.yml        # 主站开发编排
└── compose.production.yml    # 生产编排，含马原服务
```

## 本地开发

### 主站前端

建议使用 Node.js 22 与 npm，与仓库容器构建环境保持一致。

```bash
git clone https://github.com/wenckerwan/guanlan.git
cd guanlan/apps/web
npm ci
npm run dev
```

默认开发地址为 `http://localhost:3000`，以启动输出为准。前端测试和构建也在此目录执行：

```bash
npm test
npm run build
npm run preview
```

**前端启动不等于完整应用启动。** 默认浏览器 API 地址是 `/api/v1`，服务端内部地址是 `http://api:9501/api/v1`。纯本机运行时需另行启动后端并配置可达地址或同源代理，才能使用内容请求、登录和学习记录等功能。运行时配置见 [Nuxt 配置](apps/web/nuxt.config.ts)。

仓库提供 [主站开发编排](docker-compose.yml)，但其中使用固定的本地示例配置，且不包含马原服务；不要将它直接用于公网部署，也不要以为复制环境模板会覆盖其中的硬编码值。完整部署使用下述生产编排。

### 内容工具与验证

在仓库根目录执行：

```bash
# 检查 README 与文档的相对链接，不修改数据
python tools/doclink.py

# 数据导入工具单元测试
python -m unittest discover -s tools/ingest -p "test_*.py" -v
```

需要重新生成主站数据集时，先准备完整的原始资料，并安装 Python `Markdown` 依赖：

```bash
python -m pip install Markdown
python tools/ingest/build_all.py
```

生成流程读取 `storage/raw/`，更新 `storage/dataset/`、来源清单与数据集摘要。该操作会重写生成文件；存在单独追加的时政内容时，先核对 [时政维护流程](docs/shizheng-local-push.md)，避免被原始资料重建覆盖。

仓库还提供离线综合验证脚本，无需 PHP 或 Docker，但仍需要 Python、Node.js、前端依赖及原始资料：

```bash
# Linux / macOS / WSL
bash tools/verify.sh
```

```powershell
# Windows
powershell -ExecutionPolicy Bypass -File tools/verify.ps1
```

综合验证包含数据集重新生成，并非只读检查；在干净工作树或已备份的数据上运行。马原有独立的测试与构建脚本，详见 [马原验证文档](vendor/mayuan/docs/verification.md)。

## 环境配置

[本地模板](.env.example) 与 [生产模板](.env.production.example) 分别用于说明开发配置与生成正式环境文件。不要提交真实环境文件、访问令牌或数据库密码。

| 配置 | 用途与注意事项 |
| --- | --- |
| `APP_ENV`、`APP_KEY` | 运行环境与随机应用密钥；生产使用 `production` |
| `APP_PORT` | 生产网关回环端口，默认 `8080` |
| `DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD` | 主站数据库与业务账号；容器内数据库地址是 `mysql:3306` |
| `MYSQL_ROOT_PASSWORD` | 独立设置的数据库管理员密码，不与业务密码共用 |
| `REDIS_HOST`、`REDIS_PORT` | 容器内 Redis 地址，通常为 `redis:6379` |
| `ADMIN_EMAIL`、`ADMIN_PASSWORD`、`ADMIN_DISPLAY_NAME` | 首次启动管理员信息；生产密码至少 12 位 |
| `NUXT_PUBLIC_API_BASE`、`NUXT_API_INTERNAL_BASE` | 主站浏览器与服务端 API 地址；生产编排已设置同源和内网地址 |
| `NUXT_PUBLIC_MAYUAN_BASE`、`NUXT_PUBLIC_HISTORY_BASE` | 主站两个学习模块入口，默认 `/mayuan/` 与 `/history/` |
| `MAYUAN_ORIGINS` | 马原 API 允许的精确站点来源，不带尾斜杠 |

环境变量的实际注入方式以 [生产编排](compose.production.yml) 为准；将变量写入环境文件，不代表每个服务都会自动读取它。邮件等可选配置也应按编排和实际启用需求设置。

## 生产部署

目标环境为 Linux、Docker Engine 与 Compose v2。现有部署脚本要求至少 3.5 GB 总内存与 2 GB 可用磁盘；构建峰值、备份和内容资源还需要额外余量。

```bash
# 在仓库根目录准备配置
cp .env.production.example .env.production

# 编辑配置：填写数据库、管理员信息，并为密钥和密码生成独立随机值
# 每次调用生成一个随机值，不要复用
openssl rand -hex 32
chmod 600 .env.production
```

**首次部署请先阅读两份手册：** [主站部署](docs/deployment.md) 与 [马原接入部署](docs/mayuan-deployment.md)。当前生产编排包含马原服务，需按马原手册先初始化独立 `mayuan` 数据库并为业务账号授权，再启动相应服务。

完成环境配置和数据库准备后，使用现有脚本：

```bash
./tools/deploy/deploy.sh .env.production
./tools/deploy/healthcheck.sh .env.production
```

外层 Nginx 或宝塔将域名转发到 `http://127.0.0.1:8080`，并负责 TLS 证书。更新、备份与恢复说明见主站部署手册；上线前逐项核对 [生产验收清单](docs/production-acceptance-checklist.md)。部署脚本的基础健康检查不替代马原图谱、史纲交互和账号功能的浏览器验收。

## 内容与边界

主站资料经过离线导入、清洗与结构化后入库。当前提交的 [数据集清单](storage/dataset-manifest.json) 记录了 **42 套试卷、1479 道题、13 篇真题分析、5 条时政内容、10 篇预测和 1 套模拟卷**。这是仓库数据快照，不是线上数据库实时统计；后台编辑与后续发布可能使两者不同。

- **来源可追溯**：保留来源清单、文件摘要及内容出处。原始笔记、讲义、教材和整理稿可能有识别或编写误差，复习结论应与正式教材及权威发布核对。
- **原创题不冒充真题**：马原练习为原创单选题，其统计不能等同于历年真题成绩；预测和模拟内容也不构成命中保证。
- **时政由人工维护**：当前采用本地整理、审核后推送的流程，不是服务器自动实时抓取。详见 [时政维护说明](docs/shizheng-local-push.md)。
- **完整性不等于正确性**：主站数据集使用 SHA-256、字节数和条目数等校验，启动时检查摘要一致性；它能发现数据文件不匹配，不能证明事实准确或来源授权。
- **原始资料并非通用依赖**：导入清单中的本机资料路径不是其他机器的运行前提；重新导入时需自行准备对应资料与使用权限。
- **代码与资料权利分开**：仓库展示不代表第三方教材、讲义、题目或文档获得重新分发及商业使用授权。使用或分发前应确认相关权利，涉及个人错题与学习数据时应注意隐私。

## 文档导航

| 文档 | 内容 |
| --- | --- |
| [下次开发计划](下次开发计划.md) | 下一轮首要入口：最高优先级发布阻断项、验收条件及完整检查报告 |
| [主站部署手册](docs/deployment.md) | 环境准备、反向代理、备份、更新与排错 |
| [马原部署说明](docs/mayuan-deployment.md) | 子应用路由、数据库准备和后端对接；学习状态以当前前端模式为准 |
| [生产验收清单](docs/production-acceptance-checklist.md) | 上线与运行检查项 |
| [时政维护流程](docs/shizheng-local-push.md) | 本地审核、内容推送与清单更新 |
| [内容与网站路线图](docs/website-roadmap.md) | 内容来源映射与数据流 |
| [开发计划](docs/development-plan.md) | 阶段任务、阻塞项与验收标准 |
| [发布路线图](docs/release-roadmap.md) | 版本规划与发布目标 |
| [变更记录](CHANGELOG.md) | 历次变更与验证记录 |

设计与计划文档可能保留历史方案；判断当前功能时，以源码、环境配置和实际验收结果为准。
