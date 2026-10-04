# 马原知识宇宙接入观澜 · 部署说明

> 模式：同域部署 + Bearer 直通（方案 A）。马原 v2 源码经 git subtree 并入 `vendor/mayuan/`，与观澜同一仓库、同一编排构建。
> 前端 `https://<domain>/mayuan/`，后端 API `https://<domain>/api/v2/`。权威契约见 `vendor/mayuan/../../docs/GUANLAN_integration_plan.md` §三 与马原 `GUANLAN_TASKS.md`。

## 一、架构

| 服务 | 容器 | 说明 |
|---|---|---|
| 马原前端 | `mayuan-web` | Nuxt 3 `ssr:false` SPA，`app.baseURL=/mayuan/`，Nitro 代理 `/api/v2/*` → `mayuan-api:9501` |
| 马原后端 | `mayuan-api` | Hyperf/Swoole :9501，连观澜同实例 MySQL 的 `mayuan` schema |
| 数据库 | 观澜 `mysql` | 与观澜同实例、独立 schema `mayuan`（详表 `mayuan_records`） |

- **身份**：马原前端读观澜 Cookie `guanlan.token`，调马原 API 放 `Authorization: Bearer`；马原后端内网调观澜 `GET /api/v1/auth/me` 验证（`GUANLAN_API_INTERNAL_BASE`，60s 进程缓存）。
- **收藏/笔记**：权威在观澜 `/api/v1/study/*`（4 类 `mayuan_*` 已在白名单）；马原 state 仅作展示缓存。
- **学习摘要**：马原 outbox 用该用户人态 token 推 `PUT /api/v1/integrations/mayuan/summary`；token 存马原服务端 DB（`guanlan-token:<sha256(subject)>`，不落日志/payload/导出）。

## 二、首次部署步骤

1. **建 mayuan schema 并授权**（观澜 MySQL 默认只建 `guanlan` 库）：

```sql
CREATE DATABASE IF NOT EXISTS mayuan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON mayuan.* TO '<DB_USERNAME>'@'%';
FLUSH PRIVILEGES;
```

在服务器上（root 密码在 `.env.production` 的 `MYSQL_ROOT_PASSWORD`）：

```bash
cd /www/wwwroot/guanlan
docker compose --env-file .env.production -f compose.production.yml exec -T mysql \
  sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -e "CREATE DATABASE IF NOT EXISTS mayuan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON mayuan.* TO \"$MYSQL_USER\"@\"%\"; FLUSH PRIVILEGES;"'
```

> 注：`$MYSQL_USER` 是 mysql 容器内的环境变量（= `.env.production` 的 `DB_USERNAME`）。马原表 `mayuan_records` 由 `Storage` 构造时 `CREATE TABLE IF NOT EXISTS` 自动建，无需手动迁移。

2. **环境变量**：`.env.production` 增加 `MAYUAN_ORIGINS=https://<domain>`（精确来源，无尾斜杠）。其余马原变量已在 `compose.production.yml` 内联（`GUANLAN_API_INTERNAL_BASE`、`GUANLAN_SUMMARY_*` 均内网固定值）。

3. **构建并启动马原服务**（首次需 build，mayuan-api 的 Hyperf 镜像编译 swoole 较慢）：

```bash
cd /www/wwwroot/guanlan
docker compose --env-file .env.production -f compose.production.yml build mayuan-api mayuan-web
docker compose --env-file .env.production -f compose.production.yml up -d mayuan-api mayuan-web
```

4. **重载网关**（nginx 新增 `/mayuan/`、`/api/v2/` 分流）：

```bash
docker compose --env-file .env.production -f compose.production.yml up -d gateway
```

5. **健康检查**：

```bash
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/mayuan/        # 200
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8080/api/v2/content # 200（或马原定义的健康路径）
```

## 三、验收要点（双账号）

- 无 token 访问 `/mayuan/` 被引导至观澜登录，登录后回到 `/mayuan/`。
- 观澜登录用户在 `/mayuan/` 直接以其身份学习，无需二次登录。
- 收藏/笔记写入观澜（`/me/favorites`、`/me/notes` 可见，类型显示「马原·概念」等）；A 看不到 B 的。
- 摘要推送：个人中心 `/me` 出现马原摘要卡片（自评/正确率分开）。
- token 失效后写操作停止并保留待同步，重新登录后恢复。

## 四、注意

- `vendor/mayuan` 是 subtree，改动会随观澜仓库提交；同步回上游马原仓库用 `git subtree push`。
- 马原详细学习记录（自评/回忆/练习明细）仍在马原库，**不**进观澜；马原练习**不**写观澜 `/study/attempts`（不污染真题统计）。
- 本机无 Docker，compose/nginx 改动需在有 Docker 的环境（服务器）构建验证。
