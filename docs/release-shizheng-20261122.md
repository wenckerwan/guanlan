# 时政热点 · 事件时间线发布记录（2026-11-22）

## 变更内容

- `/hotspots` 由「按月长文（7 期）」改为「按事件的长文时间线（154 条）」：
  一条材料一篇事件长文，带发生日期、主考模块、优先级；列表按事件日期倒序，页面按月归档并显示 `MM/DD`。
- 数据：`storage/dataset/hotspots.json` 重写为 154 条事件（2026-01-01 — 2026-11-19），
  `storage/dataset-manifest.json` 同步重算（hotspots：237214 → 653522 字节，7 → 154 项）。
  源材料为 `E:\2027考研\考研政治\时政热点` 下的 1—11 月时政资料与专题文件。
- 后端：`ArticleService::hotspots()` 改按 `published_at` 倒序；`ArticleResource` 增加 `date`；
  新增 `App\Seeder\HotspotDatasetImporter` 与命令 `shizheng:import`（只重灌 hotspots 表）。
- 前台：`ArticleGrid` 行首显示 `MM/DD`，`ArticleReader` 抬头显示事件日期与模块标签，热点页文案改为时间线口径。
- 工具与文档：`tools/shizheng-push.mjs` 支持 `date`/`module`/`period` 推导与 `--replace`；
  `docs/shizheng-local-push.md` 改写为事件数据管流程。

## 发布基线

- 生产基线 `578aff5`（当时运行中的 api/web 镜像由该提交构建），发布提交 `796d109`。
- 镜像：`guanlan-api:shizheng-20261122`（`FROM` 现网 api 镜像 + src/seeders/config 增量）、
  `guanlan-web:shizheng-20261122`（本地 `nuxt build` 产物 + `node:24.22.1-alpine`）。
- 编排：`compose.production.yml + compose.ui-release.yml + compose.tuning.yml + compose.release-images.yml
  + /www/backup/guanlan/shizheng-20261122/compose.shizheng.yml`。

## 备份

`/www/backup/guanlan/shizheng-20261122/`：

| 文件 | 内容 |
|---|---|
| `database.sql` | 发布前全库 mysqldump（8,880,725 字节） |
| `hotspots-before.json` / `dataset-manifest-before.json` | 发布前 dataset 与 manifest |
| `images-before.txt` | 发布前 api/web 镜像 ID |
| `git-sha.txt` | 发布前仓库 HEAD（`ff34b1a`，与运行镜像不一致，属既有状态） |
| `compose.shizheng.yml` | 指向本次镜像的编排覆盖 |

## 执行步骤

1. `mysqldump` 备份数据库；记录镜像与 dataset 指纹。
2. 构建 api 增量镜像与 web 产物镜像。
3. 上传 `storage/dataset/hotspots.json` 与 `dataset-manifest.json`；
   `docker run --rm -v storage:/opt/guanlan/storage:ro ... bin/verify-dataset.php` → `Dataset integrity OK: 8 verified`。
4. `shizheng:import --force`（该表 `content_maintenance.maintained = 1`，先解除导入保护）→ 导入 154 条。
5. `compose up -d --no-deps api web` 切换容器；两个容器 healthy。

## 验证结果

| 项目 | 结果 |
|---|---|
| `/api/v1/hotspots` | 154 条，`date` 齐全，倒序正确，11 个月份分组 |
| `/hotspots` 页面 | 200，348 KB SSR 输出，卡片显示 `11/19`、`11/18` 等日期 |
| 详情页 | 首条 200（含 h2/表格/引用块）；游客第 5 条起 403（原有配额规则） |
| 首页热点卡 | 取最新 S 级事件（APEC 深圳展望、孙中山 160 周年等） |
| 其他页面 | `/`、`/analysis`、`/predictions`、`/papers`、`/practice/current-affairs`、`/mistakes`、`/search`、`/mayuan/`、`/history/` 全部 200 |
| 数据库 | `hotspots` 154 行，`published_at` 2026-01-01 — 2026-11-19 |
| 日志 | api / web 无异常 |

## 回滚

```bash
cd /www/wwwroot/guanlan
# 1) 镜像回退
docker compose --env-file .env.production -f compose.production.yml -f compose.ui-release.yml \
  -f compose.tuning.yml -f compose.release-images.yml up -d --no-deps api web
# 2) 内容回退（旧 5 期长文）
cp /www/backup/guanlan/shizheng-20261122/hotspots-before.json storage/dataset/hotspots.json
cp /www/backup/guanlan/shizheng-20261122/dataset-manifest-before.json storage/dataset-manifest.json
# 3) 如数据库需恢复：mysql < database.sql（会连带覆盖学习记录，谨慎）
```

> 旧 slug（`2026-1-6-e9ab1059` 等 5 条）随整表重灌下线，返回 404；回滚步骤 1—2 可恢复旧内容与旧页面。

## 备注

- 生产仓库 HEAD 与实际运行镜像原本不一致（HEAD 为 `ff34b1a` 的 Lenso 分支，运行镜像来自 `578aff5`）。
  本次以运行镜像的源码基线 `578aff5` 为父提交，发布提交 `796d109` 已快进到 `main`，使 `main` 与线上一致。
- 2026-11-22 起时政内容以「事件」为最小单位维护，新增一期时按 `docs/shizheng-local-push.md` 走
  `node tools/shizheng-push.mjs --input` + `shizheng:import`。

---

## 追加：时政预测按题位重整（2026-11-23）

- `/predictions` 由「10 篇混编长文」（真题反推 + 热点预测 + 原文精选 + 专题精选 + 每日增补）改为
  **按题位拆分的 10 篇**：命题规律总纲 1 篇、选择题 Q15/Q16/Q32/Q33 各 1 篇、分析题 Q34—Q38 各 1 篇。
  其中「原文精选 / 专题精选 / 每日增补」7 篇与 `/hotspots` 事件条目重复，按决定下架。
- 数据：`storage/dataset/predictions.json` 重写（49 KB，10 条，`layer` + `sort_order` 决定分组与顺序），
  manifest 同步（predictions 10 条不变，字节数更新）。
- 后端：新增 `PredictionDatasetImporter` 与 `predict:import` 命令（只重灌 predictions 表）。
- 镜像：`guanlan-api:pred-20261123`、`guanlan-web:pred-20261123`；编排覆盖
  `/www/backup/guanlan/pred-20261123/compose.pred.yml`；旧 predictions 表数据备份为
  `/www/backup/guanlan/pred-20261123/predictions-before.sql`。
- 验证：`/api/v1/predictions` 10 条且按题位顺序、游客 3 免 7 锁；`/predictions` 200 且渲染新文案；
  详情页含目录与表格；旧 slug `2027-1293509f` 等返回 404；api / web 健康、日志无错误。
- 已知修复：导入器最初用 `TRUNCATE` 清表，会隐式提交并打断 `ContentMaintenance` 事务
  （报 `There is no active transaction`）。已改为 `DatasetReader::truncate()`（DELETE），重跑导入无异常。
