# 分析文章定向发布

仓库快照由 13 篇改为 11 篇，其中“选择题规律”4 篇；发行版正文不变，目录去掉文档标题后为 37 节。两篇旧稿退出数据集，线上保留原记录、主键及收藏、评论、笔记和进度。旧链接显示下架说明，并在发行版可见时提供入口。历史评论保留在旧稿关联下，不展示在发行版。

分析数据文件为 317,347 字节，保留的 11 篇正文未改，目录均去掉文档标题。生成器可用 `python tools/ingest/build_articles.py --only analysis` 单独更新分析和清单。热点和预测数据文件保持原样。API 构建使用仓库根目录，旧的数据子目录副本已经移除，镜像内包含数据清单和源清单。

本次上线使用 `analysis:publish-release`。不要运行 `ArticleSeeder` 或清零 `content_maintenance.maintained`；文章 Seeder 也会重写热点和预测。

镜像推送前，`Release images` 工作流使用隔离的 MySQL、Redis 与生产 Nginx 路由验证同一组候选镜像。已构建镜像时，也可以在 Linux Docker 主机运行：

```sh
bash tools/deploy/check_analysis_images.sh API_IMAGE WEB_IMAGE
```

该命令仅向隔离的空库装载测试数据；线上发布仍用定向命令。部署后可以对实际网关执行只读验收：`python3 tools/deploy/check_analysis_gateway.py BASE_URL`。

以下命令从服务部署目录运行，示例使用 `compose.production.yml`。应先验证新镜像启动和挂载的 `storage` 清单一致，再发布文章。实际生产写入须经授权。

## 1. 只读预检与备份

```sh
umask 077
backup_dir="$(mktemp -d /root/backups/guanlan-analysis-release.XXXXXX)"
docker compose -f compose.production.yml exec -T api php bin/verify-dataset.php
docker compose -f compose.production.yml exec -T api \
  php bin/hyperf.php analysis:publish-release --no-ansi > "$backup_dir/preflight.log"
python3 - "$backup_dir/preflight.log" > "$backup_dir/preflight.json" <<'PY'
import json, sys
with open(sys.argv[1], encoding="utf-8") as log:
    for line in log:
        try:
            report = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(report, dict) and "token" in report and "blockers" in report:
            print(json.dumps(report, ensure_ascii=False, indent=2))
            break
    else:
        sys.exit("No structured preflight report found")
PY
```

核对报告的 `blockers`、`changes`、`skipped_outlines`、`articles`、`references` 和 `visible_after`。发现额外文章、缺少预期发行版、分类或排序不符合发布预期、发行版目录被跳过且尚未核对时，先对账。关联统计按旧 slug 的准确匹配及收藏相对链接统计；客户端本地记录不在数据库统计内。

命令退出非零时先查看 `preflight.log` 和报告中的阻止项。使用部署环境的备份流程，备份并核对 `analysis_articles`、`article_revisions`、维护标记和相关用户数据；记录热点、预测的对账基准。确认备份可读取、差异已审核后，运行 `token="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["token"])' "$backup_dir/preflight.json")"`。

## 2. 应用与保管凭据

```sh
container_dir="$(docker compose -f compose.production.yml exec -T api \
  mktemp -d /tmp/guanlan-analysis-release.XXXXXX)"
docker compose -f compose.production.yml exec -T api \
  php bin/hyperf.php analysis:publish-release --apply "$token" \
  --receipt "$container_dir/rollback.json"
docker compose -f compose.production.yml cp \
  "api:$container_dir/rollback.json" "$backup_dir/rollback.json"
chmod 600 "$backup_dir/rollback.json"
```

有变更才生成回退凭据。`changed: 0` 表示当前已无待应用的变更，不执行复制。预检以来，分析行或数据源如有变化，应用会拒绝执行，需重新预检。若写入事务失败，凭据可能已生成，但与数据库当前版本不匹配，不能当作成功凭据使用。确认成功后立即把凭据复制到宿主机；容器重建会丢失 `/tmp`。

## 3. 上线验收

- `/analysis` 可见 11 篇；“选择题规律”可见 4 篇；发行版列首，正文目录 37 节。
- 游客前 3 篇仍可读，第 4 篇保留登录限制；两个旧链接显示归档说明，发行版入口继续遵循正常访问规则。
- 两篇旧稿记录及关联仍在；人工正文未覆盖；热点、预测及维护标记与对账基准一致。

## 4. 回退

把宿主机凭据带入当前 API 容器的私有目录，使用发布时的同一数据库：

```sh
container_dir="$(docker compose -f compose.production.yml exec -T api \
  mktemp -d /tmp/guanlan-analysis-release.XXXXXX)"
docker compose -f compose.production.yml cp \
  "$backup_dir/rollback.json" "api:$container_dir/rollback.json"
docker compose -f compose.production.yml exec -T api chmod 600 "$container_dir/rollback.json"
docker compose -f compose.production.yml exec -T api \
  php bin/hyperf.php analysis:publish-release --rollback \
  --receipt "$container_dir/rollback.json"
```

回退恢复本次改动的状态和目录，追加版本历史。涉及的文章如果在发布后又被修改，整次自动回退停止，请根据发布凭据、版本历史和备份人工合并。
