# 分析文章定向发布

仓库快照 11 篇，其中「选择题规律」4 篇。分析文章正文自 2026-11-20 起由后台编辑接口维护（`content_source=admin`）。预测文章正文由 `content:sync-predictions` 维护；生产 dataset 文件用于启动校验和来源对账，可能落后于数据库。`analysis:publish-release` 只处理归档状态与目录，不再改动正文；目录节数以线上为准，不再固定为 37 节。文章数据已于 c556776 移出 git（storage/dataset 下 analysis_articles/hotspots/predictions 三份），数据集校验器对缺失文件跳过、对 hash 不符仍报错。

分析数据文件为 317,347 字节，保留的 11 篇正文未改，目录均去掉文档标题。生成器可用 `python tools/ingest/build_articles.py --only analysis` 单独更新分析和清单。热点和预测数据文件保持原样。API 构建使用仓库根目录，旧的数据子目录副本已经移除，镜像内包含数据清单和源清单。

本次上线使用 `analysis:publish-release`。不要运行 `ArticleSeeder` 或清零 `content_maintenance.maintained`；文章 Seeder 也会重写热点和预测。

镜像推送前，`Release images` 工作流在隔离 MySQL 库中用 13 篇合成文章验证定向发布、事务失败、回退和版本冲突，再通过生产 Nginx 路由检查发布后的状态。文章正文文件不再由 Git 跟踪；生产环境通过只读 `storage` 挂载提供这三份文件及对应清单。已构建镜像时，也可以在 Linux Docker 主机运行：

```sh
bash tools/deploy/check_analysis_images.sh API_IMAGE WEB_IMAGE
```

该命令只在隔离库中写入合成文章。部署后可以对实际网关执行只读验收：`python3 tools/deploy/check_analysis_gateway.py BASE_URL`。

以下命令从服务部署目录运行，示例使用 `compose.production.yml`。应先验证新镜像启动和挂载的 `storage` 清单一致，再发布文章。实际生产写入须经授权。

改正文的路径是后台编辑（PATCH /admin/analysis/{id}，带 expectedRevision，自动生成修订记录）；禁止用 db:seed 或全量 Seeder，那会同时重写热点与预测。

## 0. 正文更新的两条路（先读）

仓库不保存文章正文。线上正文以数据库为准；生产 `storage/dataset/` 下的 JSON 用于启动校验（`verify-dataset`）与来源对账，可能落后于数据库。`dataset-manifest.json` 记录的是这些文件的 SHA-256，不是线上正文的 SHA-256。

- **分析文章（11 篇，`analysis_articles`）**：走后台编辑接口 `PATCH /admin/analysis/{id}`，必须带 `expectedRevision`。保存后重算 `html`、`outline`、`word_count`，并生成 `article_revisions`，可逐篇恢复。
- **预测文章（10 篇，`predictions`）**：在 API 容器内运行 `php bin/hyperf.php content:sync-predictions --source=<path> [--apply --receipt=<path>]`。`--source` 指向通过清单校验的 `storage/dataset/predictions.json`。
  - 不带 `--apply` 是只读预检，输出 `changes` 和 `unchanged`。
  - 只更新 `title`、`summary`、`html`、`outline`、`word_count`，不动 `slug`、`layer`、`sort_order`、`priority`、`status`。
  - 白名单固定为 10 篇。若本次首次全量更新预期 10 篇都有变化，而 `changes` 少于 10，先核对 `unchanged`，排查本地是否重新生成、传输是否完整；已更新的文章会列入 `unchanged`。
  - `--apply` 必须带 `--receipt`。回执保存完整旧值及旧 `html` 的 SHA-256；回退用 `--rollback --receipt=<回执>`。

**禁止 db:seed 或运行任何 Seeder。** 全量 `ArticleSeeder` 会同时重写 `analysis_articles`、`hotspots` 和 `predictions`；生产 dataset 文件可能落后于数据库。导入前会检查 `content_maintenance.maintained`：这三张表任一标记为 `1` 时，直接拒绝并报错，不会覆盖已维护的正文；只有三个标记均为 `0` 时，导入才会通过这项保护检查。清零前必须先备份并对账分析文章与预测文章的正文。

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

- `/analysis` 可见 11 篇；“选择题规律”可见 4 篇；发行版列首；目录以线上实际显示为准（节数不固定）。
- 游客前 3 篇仍可读，第 4 篇保留登录限制；两个旧链接显示归档说明，发行版入口继续遵循正常访问规则。
- 两篇旧稿记录及关联仍在；人工正文未覆盖；热点、预测及维护标记与对账基准一致。
- 分析正文走后台编辑接口，预测正文走 `content:sync-predictions`；`analysis:publish-release` 只处理归档状态与目录，不改正文。

## 4. 回退

- **分析正文**：从该篇的 `article_revisions` 恢复到上一修订，不要整库回滚。
- **预测正文**：将权限为 `0600` 的宿主机回执复制到 API 容器的私有目录，并在容器内运行 `php bin/hyperf.php content:sync-predictions --rollback --receipt=<容器内回执路径>`。回执必须保存在宿主机 `/www/backup/guanlan/releases/<tag>/`，权限 `0600`；容器重建会清除 `/tmp`。回退前会核对现值仍与应用后的值一致。

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
