# 生产部署手册（宝塔 + Docker）

本手册面向目标服务器：2 vCPU / 4 GB RAM / 100 GB 磁盘 / 8 Mbps 上行带宽，Debian 12，
宝塔面板 10.0（已装 LNMP）与 Docker Engine + Compose v2 已就绪，中国香港地区。

部署目录固定为 `/www/wwwroot/guanlan`。应用全部运行在 Docker 容器中，宝塔只负责域名、
Let's Encrypt 证书、HTTPS 终止和反向代理；**不使用宝塔自带的 MySQL、PHP、Redis 或站点运行目录**。
因此无需在宝塔里为本站创建数据库或安装 PHP/Redis 扩展，容器内已有独立的 MySQL 8.4、Redis 7、PHP/Hyperf。

> 边界说明：生产应用端口只绑定 `127.0.0.1:8080`，不直接对公网开放；公网只放行宝塔使用的 80/443。
> 真实密钥保存在服务器上的 `.env.production`（`chmod 600`），不进入 Git 与备份归档。

## 1. 服务器基线与交换分区

首次部署前，先给服务器配置 2 GB swap 作为构建峰值保护（不是长期容量）：

```bash
# 以 root 执行；已存在 swapfile 时先检查，避免重复创建
fallocate -l 2G /swapfile
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
echo '/swapfile none swap sw 0 0' | tee -a /etc/fstab

# 降低换页倾向，优先使用物理内存
sysctl vm.swappiness=10
echo 'vm.swappiness=10' | tee -a /etc/sysctl.conf
```

验收基线：`free -m` 显示至少 4 GB 内存与 2 GB swap；`docker compose version` 可正常输出。

## 2. 克隆代码到 /www/wwwroot/guanlan

```bash
mkdir -p /www/wwwroot
git clone https://github.com/wenckerwan/guanlan.git /www/wwwroot/guanlan
cd /www/wwwroot/guanlan
git checkout main
```

后续所有部署命令都在 `/www/wwwroot/guanlan` 下执行。

## 3. 生成 .env.production

以仓库内不含密钥的 `.env.production.example` 为模板，在服务器上生成真实配置：

```bash
cd /www/wwwroot/guanlan
cp .env.production.example .env.production

# 逐项生成 32 字节随机值，粘贴到 .env.production 对应行
openssl rand -hex 32   # APP_KEY
openssl rand -hex 32   # DB_PASSWORD
openssl rand -hex 32   # MYSQL_ROOT_PASSWORD
openssl rand -hex 32   # ADMIN_PASSWORD（至少 12 位，生产无默认值）
```

必填项与取值：

| 变量 | 说明 |
|---|---|
| `APP_PORT` | 默认 `8080`，仅绑定 `127.0.0.1` |
| `APP_KEY` | `openssl rand -hex 32` 生成 |
| `APP_ENV` | 固定 `production` |
| `DB_HOST` / `DB_PORT` | `mysql` / `3306`（容器内地址，勿改） |
| `DB_DATABASE` / `DB_USERNAME` | 自定库名与业务账号 |
| `DB_PASSWORD` | `openssl rand -hex 32` 生成，独立随机 |
| `MYSQL_ROOT_PASSWORD` | `openssl rand -hex 32` 生成，独立随机 |
| `REDIS_HOST` / `REDIS_PORT` | `redis` / `6379`（容器内地址，勿改） |
| `ADMIN_EMAIL` / `ADMIN_PASSWORD` / `ADMIN_DISPLAY_NAME` | 首次启动创建的管理员，密码至少 12 位 |

保存后收紧权限并验证：

```bash
chmod 600 .env.production
ls -l .env.production   # 期望 -rw------- 1 root root
```

`compose.production.yml` 使用 `${VAR:?错误信息}` 语法拒绝空必填值，漏填会在 `deploy.sh` 的
配置校验阶段直接报错。`.env.production` 已加入 `.gitignore`，绝不提交、绝不放进备份归档。

## 4. 首次部署

```bash
cd /www/wwwroot/guanlan
./tools/deploy/deploy.sh
```

`deploy.sh` 会依次检查 Compose 版本、环境文件存在性、至少 2 GB 空闲磁盘与至少 3.5 GB 总内存，
校验 Compose 配置，构建镜像并启动，然后最多 30 次、每次间隔 2 秒轮询健康检查。全程以
`docker compose --env-file .env.production -f compose.production.yml` 驱动。

健康接口为 `GET /api/v1/health`（期望 200）、首页 `/`（期望 200）、未登录
`/api/v1/auth/me`（期望 401），且只访问 `127.0.0.1`。退出码 0 表示部署健康。

## 5. 宝塔站点、证书与反向代理

1. 宝塔「网站」中新建站点，域名填真实域名，**不要**创建数据库、**不要**装 PHP（任选即可，不启用）。
2. 站点「SSL」中申请 Let's Encrypt 证书，勾选「强制 HTTPS」。
3. 站点「反向代理」中新增目标：

```text
目标 URL：http://127.0.0.1:8080
发送域名：$host
```

宝塔只做 HTTP/HTTPS 终止与转发；证书续签、域名解析和 80→443 跳转均由宝塔负责，应用容器不碰证书。

## 6. 保留转发头与超时

反向代理配置必须保留以下请求头（宝塔默认通常已含，需逐项确认）：

```text
Host
X-Real-IP
X-Forwarded-For
X-Forwarded-Proto
```

并将代理读取/连接超时设置为 60 秒，避免长请求被提前断开。容器内网关 Nginx
（`docker/nginx/production.conf`）已保留同名头，并分别把 `/api/` 转发到 `api:9501`、
其余路径转发到 `web:3000`。

## 7. 安全组与防火墙端口

公网只放行三个端口；8080 保持仅回环，不加入安全组或公网防火墙：

| 端口 | 用途 | 公网 |
|---|---|---|
| 22 | SSH | 允许 |
| 80 | HTTP（跳转 HTTPS） | 允许 |
| 443 | HTTPS | 允许 |
| 8080 | 应用入口 | 禁止（仅 `127.0.0.1`） |

云安全组与系统防火墙（如宝塔防火墙/UFF）都按此放行。验证：

```bash
ss -tlnp | grep 8080   # 期望只出现 127.0.0.1:8080
```

## 8. 定时备份

在宝塔「计划任务」中新增 Shell 任务，每日夜间执行：

```bash
/www/wwwroot/guanlan/tools/deploy/backup.sh
```

`backup.sh` 使用 `mysqldump --single-transaction --routines --triggers` 做一致性导出，
归档含 `database.sql`、`VERSION`、`git-sha.txt`、`dataset-manifest.json` 与 `SHA256SUMS`，
默认写入 `/www/backup/guanlan`，`umask 077`，自动保留最新 7 份并清理更早归档；
**绝不包含 `.env.production`**。脚本在 stderr 打印过程日志，stdout 输出归档绝对路径，
便于任务日志查看。

## 9. 更新、恢复、日志、健康检查与回滚

```bash
cd /www/wwwroot/guanlan

# 日常更新：备份 → ff-only 拉取 main → 重建 → 健康检查；失败自动回滚到旧 SHA
./tools/deploy/update.sh

# 健康检查（HTTP 200/401 断言 + 服务状态）
./tools/deploy/healthcheck.sh

# 查看近 30 分钟日志
docker compose --env-file .env.production -f compose.production.yml logs --since 30m --tail 100

# 列出备份
ls -l /www/backup/guanlan

# 恢复：必须显式传入归档；先自动做安全备份，再 DROP/CREATE 后灌入 database.sql
./tools/deploy/restore.sh /www/backup/guanlan/guanlan-YYYYMMDD-HHMMSS.tar.gz

# 回滚到上一提交（人工场景）
git log --oneline -5
git reset --hard <旧 SHA>
./tools/deploy/deploy.sh
```

`update.sh` 拒绝脏工作树，拉取失败、构建/健康检查失败都会自动 `git reset --hard` 到旧 SHA
并重建旧镜像；`restore.sh` 先校验归档内嵌 `SHA256SUMS`，恢复前再生成一份安全备份，
使用 `DROP DATABASE IF EXISTS` + `CREATE DATABASE` 保证是替换而非累加。

## 10. 故障排查

| 现象 | 排查与处理 |
|---|---|
| 低内存（构建 OOM、服务被杀） | `free -m` 确认 2 GB swap 与 `vm.swappiness=10`；`docker stats` 查看容器内存上限（MySQL 1024M / API 768M / Web 512M / Redis 192M / gateway 128M）；必要时先 `docker compose stop` 非必要服务再构建 |
| 数据集完整性失败（`missing file` / `sha256` / `bytes/items/groups` / `source_manifest` mismatch） | 部署失败于 migration 前。重新生成 `python tools/ingest/build_all.py`；来源清单不匹配先跑 `python tools/ingest/manifest.py`；不要手工改摘要 |
| 容器 unhealthy | `docker compose ps` 查看健康状态；`docker compose logs <服务> --tail 100`；MySQL 确认 `mysqladmin ping`，API 确认 `php` 探测 9501，gateway 等待 web/api 健康后才接流量 |
| 端口冲突 | `ss -tlnp` 确认 `127.0.0.1:8080` 未被占用；若宝塔自带 Nginx 占用了 80/443 属预期，应用容器不监听公网端口；`.env.production` 中 `APP_PORT` 改动后重新跑 `deploy.sh` |

## 职责边界（重要）

- 宝塔自带 MySQL、PHP、Redis **均不被观澜使用**；不要在宝塔中为本项目建库或启用 PHP。
- 应用全容器化：MySQL 8.4、Redis 7、PHP/Hyperf、Nuxt Web 与内部 Nginx 都在
  `compose.production.yml` 的独立网络中运行，数据落在命名卷 `mysql_data` / `redis_data`。
- 宝塔只负责域名解析、Let's Encrypt 证书、HTTPS 终止与反向代理。
