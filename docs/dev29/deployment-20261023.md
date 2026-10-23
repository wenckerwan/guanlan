# DEV29 合入 main 与生产核验（2026-10-23）

## 结果

- 用户本轮明确授权“合入 main，部署”，解除此前维护期间不部署的限制。
- 已将 DEV29 业务提交 `976f5c92d38a51c78c58dfafeabe41e49ead63b8` 快进合入本地 main，并推送 GitHub main；推送结果为 `5ed992d..976f5c9 main -> main`。
- SSH 核对发现生产在本轮开始前已经运行同一 DEV29 提交，发布目录状态为 ACTIVE；本轮完成线上验收和来源核对，没有重复切换相同镜像。
- 首页、史纲、马原、健康接口和匿名权限边界通过；备份、回退镜像、内容摘要和四个受保护容器的一致性核对通过。
- 后续验收文档提交不改变业务源码；生产 checkout 保持发布清单绑定的 976f5c9，确保已有回退的提交漂移检查仍有效。

## 生产来源与回退依据

| 项目 | 核对值 |
|---|---|
| 服务器目录 | /www/wwwroot/guanlan |
| 生产 HEAD | 976f5c92d38a51c78c58dfafeabe41e49ead63b8，detached HEAD |
| 发布 ID / 状态 | dev29-976f5c9 / ACTIVE |
| 发布清单 | /www/backup/guanlan/releases/dev29-976f5c9/release.json |
| 数据库备份 | 同目录 database.sql，SHA-256 与发布清单一致 |
| API 镜像 | guanlan-api:release-dev29-976f5c9 |
| API image ID | sha256:5dbbb8308f4a13e9a339f491aecfd10406705cb54215853516a41f3c4d086cf2 |
| Web 镜像 | guanlan-web:release-dev29-976f5c9 |
| Web image ID | sha256:bad39dc2ffe820f49ecea8995173fecd2c6e0f9aed0ca7404e9a07310dcac81d |
| Gateway 镜像 | guanlan-gateway:release-dev29-976f5c9 |
| Gateway image ID | sha256:c59d1f8b1e0e42da75be973424cd72e23f7f8adfb54e982f4d1cd0216a5a5576 |

三项运行 image ID 与 release.json 的候选镜像一致；`rollback-dev29-976f5c9` 的 API/Web/Gateway 标签存在，image ID 与清单一致。七个观澜服务均 healthy。

生产 API、Web、Gateway 容器创建时间分别为 2026-10-22 22:10:40、22:10:42、22:10:51（Asia/Singapore），证明本轮连接前已经部署。本次不将旧部署的执行过程记成本轮新执行的发布。

## 本轮实际检查

### 合入前

重新执行完整 `tools/test_stability.py --php <PHP 8.3.36>`，退出码 0：95/95 前端、11 PHP 入口、8 数据集、3 稳定性/快照、11 发布行为通过。此前已确认同一业务提交的 CI、生产构建和隔离浏览器通过，见 [验收报告](acceptance-20261023.md)。

main 所在工作树原本干净；先快进至远端 5ed992d，再快进至已验证的 976f5c9，无冲突、无新的业务合并差异。未清理其他工作树或无关未跟踪文件。

### 生产只读一致性

调用实际发布工具的 `Release.validate()`、`check_unchanged()`、`verify()` 和 `content_digest()`，检查运行镜像、配置/环境/数据集指纹、七服务健康、匿名 HTTP 边界及八张内容表摘要；额外核对 SQL 备份摘要和旧镜像 ID。实际结果：

```json
{
  "release": "dev29-976f5c9",
  "status": "ACTIVE",
  "sha": "976f5c92d38a51c78c58dfafeabe41e49ead63b8",
  "images_match": true,
  "rollback_images_match": true,
  "backup_checksum_match": true,
  "immutable_containers_match": true,
  "health_and_anonymous_boundaries": "PASS",
  "content_digest_matches_release": true
}
```

MySQL、Redis、马原 API 和马原 Web 容器 ID 与原发布清单一致；八张内容表的行数与 SHA-256 摘要和发布时一致。`bash tools/deploy/healthcheck.sh` 返回 OK。

首次通过 PowerShell 将检查脚本送入 SSH 时，最后一行携带 CR 导致 healthcheck.sh 文件名多出回车；改用 `tr -d '\r' | bash` 后上述完整检查和 healthcheck 均成功。该错误未触发部署或改变服务。

### 公网 HTTPS

本机 Node fetch 通过 HTTPS 分别读取以下地址，状态均符合预期：

| 路径 | 状态 |
|---|---|
| / | 200 |
| /history/ | 200 |
| /mayuan/ | 200 |
| /api/v1/health | 200 |
| /api/v1/auth/me | 401 |
| /api/v1/history/events | 401 |
| /api/v1/stats/leaderboard | 401 |
| /api/v2/content | 401 |

站点：[guanlan.wencker.top](https://guanlan.wencker.top)。未发送真实邮件、调用真实模型、创建测试账号、写入学习记录、执行数据库迁移或恢复。真实模型完整链路和业务 MySQL 写入仍是独立验收项，不由这些只读检查替代。

## 合入后的 CI 环境修复

main 的运行 [39918943112](https://github.com/wenckerwan/guanlan/actions/runs/39918943112) 在安装浏览器时失败。日志明确显示 `ubuntu-latest` 已切换至 Ubuntu 26.04，锁定的 Playwright 1.58.2 不支持 `ubuntu26.04-x64`。此前的完整离线回归、部署配置检查和 Nuxt 构建均成功；浏览器和 Docker 演练因前一步失败被跳过。

修复仅将 `.github/workflows/stability.yml` 的 runner 固定到前一次成功运行所用的 `ubuntu-24.04`，保留锁定的 Playwright 和所有验收步骤。该变更不修改生产业务源码，不需要替换现有业务镜像。最终运行结果另见本节后续记录。

## 回退命令

必要时在生产目录使用：

```sh
./tools/deploy/update.sh rollback dev29-976f5c9
```

本次仅核对回退所需文件、指纹和镜像存在；没有为了验证而回退线上业务。应用回退不恢复旧数据库。
