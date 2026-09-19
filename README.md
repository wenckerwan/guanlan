# 观澜｜考研政治知识库

以教材、真题和时政热点为核心的私有学习资料站。在线后台使用 PHP/Hyperf，前端使用 Nuxt 3；Python 只用于离线资料提取、去重和 OCR。

当前版本：`V0.1-dev.2`（开发分支：`feature/content-api`；基线 Git tag：`v0.1`）

## 当前首版

- 搜索优先的响应式首页，展示最新热点、继续阅读和六大学科入口。
- 六大学科资料库总览、学科详情页、章节卡片、知识点列表、面包屑和移动端抽屉导航。
- Hyperf 内容 API：`/api/v1/health`、`/api/v1/home`、`/api/v1/subjects`、`/api/v1/subjects/{slug}`，数据来自 MySQL。
- 首页、资料库页、学科详情页在 SSR 下经 `useApiFetch` 从 API 取数，不再依赖前端硬编码数据。
- 本地资料清单工具：按 SHA-256 去重并生成审计清单。
- Docker Compose 编排 Nuxt、Hyperf、MySQL、Redis、Meilisearch 与 Nginx。

## 开发

```bash
cd apps/web
npm install
npm run dev
```

```bash
python tools/ingest/manifest.py
docker compose up --build
```

原始资料应放在 `storage/raw/`，不要提交到版本库。

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

## V0.1-dev.2 说明

本版本补齐可启动的 Hyperf 后端骨架，将六大学科/章节/知识点、首页热点与资料卡从前端硬编码迁入 MySQL，并提供统一的 `/api/v1` 内容接口；三个前端页面改为 SSR 经 `useApiFetch` 取数。manifest→MySQL 导入器与 Meilisearch 索引顺延至 V0.1-dev.3。

## 开发规范

本 README 是本项目的开发要求文件。后续开发、版本发布和 Git 同步必须遵守本章节；若规范发生变化，应先更新 README 和 CHANGELOG，再执行对应开发工作。

### 版本规则

- 稳定版本使用大写 `V`：`V0.1`、`V0.2`、`V1.0`。
- 开发版本：`V0.1-dev.1`、`V0.1-dev.2`；测试版本：`V0.1-beta.1`、`V0.1-beta.2`。
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
- 当前版本：`V0.1-dev.2`
- Git 显示版本：`v0.1`
- 稳定分支：`main`
- 当前首版记录：见 [CHANGELOG.md](CHANGELOG.md)
- 当前版本来源：见 [VERSION](VERSION)
