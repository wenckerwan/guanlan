# V0.1-dev.5 生产部署设计

## 部署基线

目标服务器固定为：

- 套餐：AMD EPYC KVM 中配版。
- 资源：2 vCPU、4 GB RAM、100 GB 磁盘、8 Mbps / 15 Mbps 带宽。
- 系统：Debian 12，宝塔面板 10.0，已安装 LNMP 与 Docker。
- 地区：中国香港。
- 职责边界：应用全容器化；宝塔只负责域名、Let's Encrypt 证书、HTTPS 终止和反向代理。

## 目标

- 一条命令完成首次部署和后续更新。
- 生产密码、管理员凭据与应用密钥不进入 Git。
- 应用只监听 `127.0.0.1:8080`，公网仅开放宝塔管理的 80/443。
- 在 2 核 4GB 条件下稳定运行，构建阶段和运行阶段均有明确资源边界。
- 提供健康检查、日志轮转、MySQL 备份、恢复与更新回滚说明。

## 非目标

- 不复用宝塔自带 MySQL、PHP、Redis 或站点运行目录。
- 不由应用容器申请或续签 HTTPS 证书。
- 不在本阶段启用尚未被业务使用的 Meilisearch。
- 不实现多机高可用、自动扩容或云对象存储。

## 拓扑

```text
Internet
  -> 宝塔 Nginx :443 / :80
  -> http://127.0.0.1:8080
  -> Docker gateway (Nginx)
       /api/* -> api:9501
       /*     -> web:3000

Docker internal network
  api -> mysql:3306
  api -> redis:6379
```

MySQL、Redis、API、Web 和内部 Nginx 均在独立 Docker 网络中。只有内部 Nginx 发布端口，并绑定回环地址。

## 镜像与编排

新增独立的 `compose.production.yml`，不与本地 `docker-compose.yml` 合并，避免开发默认值进入生产。

### Web

新增 `apps/web/Dockerfile` 多阶段镜像：

1. `node:22-alpine` 构建阶段执行 `npm ci` 和 `npm run build`。
2. 运行阶段只复制 `.output`，以非 root 用户运行 `node server/index.mjs`。
3. 容器启动时不再安装依赖或编译，减少重启时间与内存峰值。

### API

沿用 PHP 8.3 + Hyperf 镜像，启动前先运行数据集完整性校验。生产编排通过环境变量传入数据库、Redis 和管理员初始化参数。

### 服务策略

所有服务使用：

- `restart: unless-stopped`。
- Docker `json-file` 日志驱动，单文件 10 MB，保留 3 个文件。
- 健康检查与依赖条件；gateway 只有在 Web 和 API 健康后接流量。
- 命名数据卷保存 MySQL 与 Redis 数据。

Meilisearch 不出现在生产编排中。当前搜索继续使用已验证的 SQL 路径，避免常驻消耗约 300-500 MB 内存。

## 资源预算

| 服务 | CPU 上限 | 内存上限 |
|---|---:|---:|
| MySQL | 0.80 | 1024 MB |
| API | 0.75 | 768 MB |
| Web | 0.50 | 512 MB |
| Redis | 0.20 | 192 MB |
| Gateway | 0.10 | 128 MB |
| 合计上限 | 2.35 | 2624 MB |

CPU 上限允许短时争用，不要求总和小于 2。内存为硬上限，给 Debian、宝塔 Nginx、Docker daemon 和文件缓存保留约 1.3 GB。服务器额外配置 2 GB swap，`vm.swappiness=10`，只作为构建峰值保护，不作为长期容量。

## 配置与密钥

提交 `.env.production.example`，实际 `.env.production` 被 `.gitignore` 排除并在服务器上设置为 `chmod 600`。

必填项：

- `APP_KEY`：至少 32 字节随机值。
- `DB_PASSWORD`、`MYSQL_ROOT_PASSWORD`：独立随机密码。
- `ADMIN_EMAIL`、`ADMIN_PASSWORD`、`ADMIN_DISPLAY_NAME`：首次启动管理员。
- `APP_PORT`：默认 `8080`，仅绑定 `127.0.0.1`。

生产 Compose 使用 `${VAR:?error}` 语法拒绝空值。管理员 Seeder 在 `APP_ENV=production` 时不再接受公开默认密码；管理员已存在时不重置密码。

## 宝塔配置

宝塔中新建实际域名站点并申请 Let's Encrypt 证书，开启强制 HTTPS。站点反向代理目标固定为：

```text
http://127.0.0.1:8080
```

代理保留 `Host`、`X-Real-IP`、`X-Forwarded-For` 和 `X-Forwarded-Proto`。服务器安全组和系统防火墙只开放 22、80、443；8080 不对公网开放。

## 部署与更新

新增 `tools/deploy/`：

- `deploy.sh`：检查 Debian、Docker、Compose、环境文件、磁盘、内存与 swap；构建并启动；轮询健康接口。
- `healthcheck.sh`：检查 Compose 服务状态、`/api/v1/health`、首页和登录未授权边界。
- `backup.sh`：执行一致性 MySQL dump，保存数据集摘要和版本信息，压缩后放入 `/www/backup/guanlan`，保留最近 7 份。
- `restore.sh`：要求显式传入备份文件，校验归档内容后恢复 MySQL；恢复前自动再做一份安全备份。
- `update.sh`：更新前备份，拉取 `main`，构建新镜像，启动并健康检查；失败时回到更新前 Git SHA 并恢复旧容器。

首次部署目录固定为 `/www/wwwroot/guanlan`。部署脚本不修改宝塔站点配置，只输出需要在宝塔界面填写的反向代理目标。

## 备份与恢复

备份归档包含：

- MySQL `--single-transaction` dump。
- 当前 Git SHA、`VERSION` 和 `storage/dataset-manifest.json`。
- Compose 配置校验结果的脱敏摘要。

不把 `.env.production` 放入普通备份归档；密钥由服务器管理员单独保管。Redis 只保存可重建或短期状态，不作为主备份源。

每次正式上线前至少完成一次“备份 -> 清空测试库 -> 恢复 -> 健康检查”的恢复演练。

## 监控与故障处理

- Docker healthcheck 负责容器级状态。
- 宝塔监控 HTTP `/api/v1/health`，连续 3 次失败告警。
- 日志通过 `docker compose -f compose.production.yml logs --since 30m` 查看。
- 磁盘使用率达到 80%、MySQL 容器重启或健康检查失败时人工介入。
- 应用更新失败时优先回滚 Git SHA 和镜像，不直接修改数据库文件。

## 验收

- 在 WSL 原生验证副本使用生产编排成功构建并启动。
- `docker compose config` 不出现 `change-me`、公开管理员密码或空必填变量。
- 宿主机只有 `127.0.0.1:8080` 监听应用端口。
- API 健康检查、首页、登录、用户态与后台冒烟通过。
- 容器内存上限与日志轮转配置可从 `docker inspect` 读取。
- 执行备份并在独立测试数据库完成一次恢复演练。
- README、部署手册、开发规划与 CHANGELOG 同步实际结果。

