# dev.29 镜像发布操作说明

当前服务器维护中，本次仅推送 GitHub；下列命令用于维护结束后另行安排的发布，不在本次执行。

发布前在独立 Linux 环境执行 CI 检查和合成数据演练：

```sh
python3 tools/test_stability.py
bash tools/deploy/test_config.sh
python3 tools/deploy/rehearse_release.py
```

独立构建并验证与目标提交匹配的 API/Web 镜像，传送并加载镜像后，在服务仓库执行：

```sh
./tools/deploy/update.sh prepare dev29-<sha> --api-image guanlan-api:<sha> --web-image guanlan-web:<sha>
# 核对备份目录内 release.json 为 PREPARED、SQL 备份及镜像 ID。
./tools/deploy/update.sh activate dev29-<sha>
# 必要时显式回退镜像：
./tools/deploy/update.sh rollback dev29-<sha>
```

prepare 记录旧/新镜像、数据库快照、配置与只读数据集摘要；activate 拒绝输入漂移，API 先执行只读数据集校验再启动。健康检查失败触发旧镜像回退，不恢复数据库、不执行迁移、不在宿主构建。ROLLBACK_FAILED 必须人工排查，不能当作成功。

完整参数、兼容性限制与备份位置见 ../deployment.md。本工具不适用于需要数据库迁移的发布。
