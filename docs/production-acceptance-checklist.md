# 服务器上线验收清单

本清单用于 `feature/content-api`（V0.1-dev.5）合并到 `main` 之前，在目标服务器（Debian 12 + 宝塔 10.0，2 vCPU / 4 GB RAM）上完成生产集成验证。每一项验证通过后再打勾。

> 与 `docs/deployment.md` 的区别：部署手册是「怎么做」，本清单是「上线前逐项确认」，对应 CHANGELOG 中标注为 SKIPPED 的 Docker / PHP / live MySQL 验证。

## 验证前

- [ ] 服务器已按 `docs/deployment.md` 第 1 节配置 2 GB swap 与 `vm.swappiness=10`
- [ ] 代码已克隆到 `/www/wwwroot/guanlan`，分支为 `feature/content-api`
- [ ] `.env.production` 已生成，权限 `600`，所有密钥非空且不含 `change-me` / `guanlan2027`

## 应用启动与冒烟

- [ ] `docker compose up --build -d` 成功，所有服务进入运行/健康状态
- [ ] `bash tools/lint.sh` 通过
- [ ] `bash tools/smoke.sh` 通过：登录返回 64 字符 token，`/auth/me` 返回 200

## 生产编排与部署脚本

- [ ] `bash tools/deploy/test_config.sh` 通过（回环绑定、无 Meilisearch、无占位密钥、日志轮转 10m×3、Web 两阶段非 root）
- [ ] `./tools/deploy/deploy.sh <env>` 完成：config 校验 → build → up → 健康轮询（30×2s）
- [ ] `./tools/deploy/healthcheck.sh <env>` 通过：`/api/v1/health`=200、`/`=200、未授权 `/api/v1/auth/me`=401

## 数据完整性负向验证

- [ ] 备份 `storage/dataset/questions.json` 后追加一行，再启动容器：校验应在 `migrate` 之前失败退出
- [ ] 恢复 `questions.json` 原文件后，容器可正常启动并通过健康检查

## 端口与资源限制

- [ ] `ss -ltn` 确认 `8080` 仅绑定 `127.0.0.1`，不存在 `0.0.0.0:8080`
- [ ] `docker inspect` 确认 API/MySQL 的内存与 CPU 上限符合设计（API 768M/0.75，MySQL 1024M/0.80）
- [ ] `docker inspect` 确认各服务日志为 `json-file`，选项 `10m` × `3`

## 备份与恢复演练

- [ ] `./tools/deploy/backup.sh <env> <dir>` 生成归档，成员含 `database.sql`、`VERSION`、`git-sha.txt`、`dataset-manifest.json`、`SHA256SUMS`，且不含 `.env.production`
- [ ] 归档校验通过，且 `SHA256SUMS` 覆盖 `database.sql`
- [ ] `./tools/deploy/restore.sh <archive> <env>` 先做安全备份，再 DROP/CREATE 后灌入，健康检查通过

## 收尾

- [ ] 宝塔站点已申请 Let's Encrypt、强制 HTTPS、反代到 `http://127.0.0.1:8080`，转发头与 60s 超时已配置
- [ ] 安全组/防火墙仅开放 22、80、443
- [ ] 夜间 `backup.sh` 定时任务已配置，保留 7 份
- [ ] 将 `docs/development-plan.md` 的 1.4 / 1.7 由「进行中」改为完成
- [ ] 合并 PR #1 到 `main`

## 验收结论

- [ ] 全部通过，可合并 PR
- [ ] 存在问题（请在下方记录，暂不合并）

问题记录：
