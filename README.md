# 观澜｜考研政治知识库

以教材、真题和时政热点为核心的私有学习资料站。在线后台使用 PHP/Hyperf，前端使用 Nuxt 3；Python 只用于离线资料提取、去重和 OCR。

当前版本：`V0.1-dev.5`（开发分支：`feature/content-api`；基线 Git tag：`v0.1`）

## 当前首版

六大内容模块全部可用，均为真实数据（来自 `F:\2027考研资料\考研政治`，经离线导入器入 MySQL）。

| 模块 | 路由 | 规模 |
|---|---|---|
| 真题回顾 | `/papers`、`/questions` | 42 卷 / 1479 题 |
| 真题分析 | `/analysis` | 13 篇 |
| 时政热点 | `/hotspots` | 3 期 |
| 时政预测 | `/predictions` | 6 篇 |
| 模拟押题 | `/mocks` | 1 套 / 38 题 |
| 个人错题分析 | `/mistakes` | 2 名考生 / 292 题 / 5 份提分手册 |

- 站内搜索 `/search`：一次聚合七类内容，支持类型筛选与结果计数。
- 用户态：注册/登录、收藏、笔记、做题记录、阅读进度、正确率统计。
- 后台 `/admin`：内容总览、用户管理、时政热点与真题分析的增删改。
- 真题支持 `reveal=0` 隐藏答案与解析，用于先做题后对答案。
- Hyperf 内容 API：52 个路由注册，统一 `{"data": ...}` 包络、对外字段 camelCase。
- 本地资料清单工具：按 SHA-256 去重并生成审计清单。
- Docker Compose 编排 Nuxt、Hyperf、MySQL、Redis、Meilisearch 与 Nginx。

站点现状与内容来源映射见 [docs/website-roadmap.md](docs/website-roadmap.md)；
**下一步开发计划与验收标准见 [docs/development-plan.md](docs/development-plan.md)**。
**生产部署（宝塔 + Docker）见 [docs/deployment.md](docs/deployment.md)**。
**上线前逐项验收清单见 [docs/production-acceptance-checklist.md](docs/production-acceptance-checklist.md)**。

## 开发

```bash
cd apps/web
npm install
npm run dev
```

```bash
python tools/ingest/manifest.py   # 本地资料清单（SHA-256 去重）
python tools/ingest/build_all.py  # 工作区只读 raw 副本 -> storage/dataset/*.json + storage/dataset-manifest.json
docker compose up --build
```

`build_all.py` 只读取工作区内的 `storage/raw/` 副本，生成 8 个数据集 JSON 和
`storage/dataset-manifest.json`。摘要记录每个文件的字节数、SHA-256、条数和分组，
并关联 `storage/import-manifest.json` 的来源哈希与统计。

API 在 migration 和 Seeder 写库前运行 `php bin/verify-dataset.php`。遇到
`missing file` 时，先重新运行 `python tools/ingest/build_all.py`；遇到 `sha256`
或 `bytes/items/groups` mismatch 时，说明数据集被改动或摘要过期，应重新生成数据集和摘要，
不要手工修改摘要；遇到 `source_manifest` mismatch 时，先运行 `python tools/ingest/manifest.py`
刷新来源清单，再运行 `python tools/ingest/build_all.py`。校验失败会汇总差异并以非零退出，
不会继续 migration 或 Seeder 写入。

### 离线验证

本机没有 PHP 与 Docker 时，仍可完成离线自检：

```powershell
powershell -ExecutionPolicy Bypass -File tools/verify.ps1
```

它会依次执行：`python -m compileall tools`、`tools/phpcheck.py`（PSR-4 / 括号配平 /
模型列引用 / 路由方法存在性 / PHP 链式语法风险）、`tools/phpcheck_selftest.py`（反向注入 6 类错误，
必须全部被抓到）、`build_all.py` 数据集重生成、以及 `apps/web` 的 `npm test`。

原始资料应放在工作区的 `storage/raw/`，不要提交到版本库。`F:\\2027考研资料\\考研政治`
是只读来源资料；需要导入时先复制到被忽略的工作区存储，再由离线工具读取。F 盘资料不会挂载到 Docker。

## 本机 Docker 工作流（WSL2，无 Docker Desktop）

本机为 Windows 10 IoT Enterprise LTSC 2021（build 19044），Docker Desktop 要求 build 19045 且 LTSC 永不升级，因此**无法安装 Docker Desktop**。改用 WSL2 内的 Docker Engine：

- 运行时：WSL2 `Ubuntu-24.04`（systemd 为 PID 1）内的 Docker Engine 29.8.1 + Compose v5.5.1。
- 网络：已配置 Docker Hub registry mirror（`docker.m.daocloud.io` 等）与 Aliyun composer packagist 镜像，规避境内直连超时。
- **compose 必须从 WSL 原生副本运行**，不要直接挂载 `/mnt/d`（9P 跨文件系统很慢，且 web 容器 `npm ci` 会用 Linux 二进制覆盖 Windows 的 `node_modules`）。标准做法是用 tar 复制到 `~/guanlan` 再构建：

```bash
wsl -d Ubuntu-24.04
cd ~ && rm -rf guanlan && mkdir guanlan
tar -cf - -C "/mnt/d/code_files/观澜｜考研政治知识库/guanlan" \
  --exclude=node_modules --exclude=.nuxt --exclude=.output --exclude=vendor --exclude=.git . \
  | tar -xf - -C ~/guanlan
cd ~/guanlan && docker compose up --build -d
docker compose logs api --tail 50          # 观察 migrate/db:seed/监听 9501
curl http://localhost:8080/api/v1/health   # 经 nginx 反代验证
```

api 容器启动链为 `migrate --force`（等待 MySQL 就绪的重试循环）→ `db:seed --force` → `start`，首次启动会自动建表并灌入种子数据。

## V0.1-dev.4 说明

本版本把 `F:\2027考研资料\考研政治` 的**时政热点、真题分析与个人错题分析**接入站点，
并补齐站点基础功能：真题回顾、模拟押题、站内搜索、登录注册、用户学习记录与后台管理。

- 六大内容模块全部可用，数据来自只读源目录经 `tools/ingest/` 离线导入 MySQL。
- 新增聚合检索端点 `GET /api/v1/search`，一次覆盖七类内容。
- 新增令牌认证（`Authorization: Bearer`，库内只存 sha256）、用户态与后台。
- 新增离线 PHP 结构检查器 `tools/phpcheck.py`（含反向自测），
  在没有 PHP/Docker 的机器上也能拦住 PSR-4、括号配平、模型列引用、路由方法缺失四类错误。
- Docker 验证通道已恢复：WSL2 `administrator` 加入 `docker` 组，普通用户可执行 Docker 29.8.1。
- 修复认证与用户态控制器中的 PHP `new Class()->method()` 语法错误，登录、收藏、笔记、进度、统计和后台接口恢复。
- 错题页增加重练交互：支持单选/多选判分、显示原错因对比、保存行动建议和 `/study/attempts` 作答记录。
- `/api/v1/mistakes/items/{id}/action` 仅允许登录用户更新行动建议。
- 容器验证：迁移与 Seeder 成功，`tools/smoke.sh` 全部通过；正常接口为 200/201，未登录为 401，参数校验为 422，重复注册为 409，预期不存在资源为 404。

## 开发规范

本 README 是本项目的开发要求文件。后续开发、版本发布和 Git 同步必须遵守本章节；若规范发生变化，应先更新 README 和 CHANGELOG，再执行对应开发工作。

**动手写代码前，先读 [docs/development-plan.md](docs/development-plan.md)。**
该文件是开发标准，定义当前阻塞项、各阶段任务、每项的完成标准与验收总则；
未列入其中的工作属于范围外变更，应先补进该文件再执行。

### 版本规则

- 稳定版本使用大写 `V`：`V0.1`、`V0.2`、`V1.0`。
- 开发版本：`V0.1-dev.1`、`V0.1-dev.3`；测试版本：`V0.1-beta.1`、`V0.1-beta.2`。
- README 与 CHANGELOG 对外展示大写 `V`；Git tag 使用小写标准形式，如 `v0.1`、`v0.1-beta.1`。
- `V0.1` 是当前已确认基线。新增完整功能先使用 `V0.1-dev.N`，首个可测试版本使用 `V0.1-beta.1`，完成一轮稳定功能后升级到 `V0.2`。
- 只修复错误或补充小范围文档时增加合适的后缀，不随意提升主版本号。
- 每次实际开发同步前更新根目录 `VERSION`，文件只保存当前版本字符串；没有实际变更时不创建提交、不增加版本号。

### 分支策略

- `main` 只保存可运行且已验证的稳定版本。
- 新功能使用 `feature/<topic>`，错误修复使用 `fix/<topic>`，文档和开发规范使用 `docs/<topic>`。
- 合并到 `main` 前必须完成本地验证，并在 CHANGELOG 中记录结果和未解决问题。

### 提交规范

提交信息使用英文前缀加冒号，并在正文或标题中说明版本及版本后缀：

- `feat:` 新功能
- `fix:` 错误修复
- `refactor:` 重构
- `docs:` 文档
- `test:` 测试
- `chore:` 工具、依赖和配置

示例：`feat: add hotspot feed for V0.1-dev.1`。

### Git 同步清单

每次向远程同步前必须依次完成：

1. 更新 `VERSION`。
2. 更新 `CHANGELOG.md`，写明功能、修复、变更、验证和已知限制。
3. 提交信息包含本次版本或版本后缀。
4. 运行对应测试、构建或静态检查，并记录实际结果。
5. 确认没有提交 `node_modules`、缓存、临时日志、原始私有资料或密钥。
6. 检查 Git diff 后再提交、打 tag（如适用）并推送。

### CHANGELOG 格式

每个版本使用固定结构，日期采用 `YYYY-MM-DD`：

```markdown
## V0.1-dev.1 - 2026-09-07

### 新增
- ...

### 修复
- ...

### 变更
- ...

### 验证
- `node --test ...`
- `python -m unittest ...`
- `npm run build`

### 已知限制
- ...
```

`CHANGELOG.md` 是版本事实记录，验证结果必须与实际执行命令一致；没有实际变更的版本不增加记录。

### 当前基线

- 项目名称：观澜｜考研政治知识库
- 当前版本：`V0.1-dev.5`
- Git 显示版本：`v0.1`
- 稳定分支：`main`
- 当前首版记录：见 [CHANGELOG.md](CHANGELOG.md)
- 当前版本来源：见 [VERSION](VERSION)
- 下一步开发计划：见 [docs/development-plan.md](docs/development-plan.md)
- 站点现状快照：见 [docs/website-roadmap.md](docs/website-roadmap.md)
