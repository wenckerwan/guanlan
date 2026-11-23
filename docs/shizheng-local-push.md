# 时政热点 · 事件时间线数据管

> 时政内容采用 **seed 式数据管**：云端**不自动抓取**，由你在本地抓取、审核、
> 整理成「一条材料 = 一个事件」的长文后写入数据集，随部署入库。质量由你把关。

## 架构

```
本地抓取/整理 → 按事件拆分（每条带发生日期 date）
        → storage/dataset/hotspots.json（一事件一条，按 date 正序）
        → 重算 storage/dataset-manifest.json → git 提交部署
        → 容器内执行 php bin/hyperf.php shizheng:import（只灌 hotspots 表）
```

- 表结构：`hotspots`（基础字段 + 长文 slug/period/priority/html/outline/... + `published_at` 事件日期）。
- **排序即时间线**：`published_at` 存事件发生日，列表接口按 `published_at` 正序返回；
  同日按 id（即 dataset 顺序）。首页卡片仍按优先级取最新。
- **分组**：`period` 由 `date` 推出，形如 `2026年11月`，前台 `/hotspots` 按它分月显示，
  每条卡片带 `MM/DD` 日期，点进详情是整篇事件长文。
- **学科归属**：`module`（习思想 / 毛中特 / 史纲 / 马原 / 思法 / 当代）决定 `subject_id` 与 `tag`。
- 入库即发布：`status = published`（本地审核过才入库，无云端筛选态）。
- 幂等：导入前清空 `whereNotNull('slug')` 的旧条目后整表重灌，按 slug 唯一。

## 事件条目字段

| 字段 | 必填 | 说明 |
|---|---|---|
| `slug` | ✅ | 全库唯一。建议 `ev-YYYYMMDD-短哈希`。 |
| `title` | ✅ | 全库唯一，事件名（不带日期、序号、星号）。 |
| `date` | ✅ | 事件**发生日** `YYYY-MM-DD`；跨日事件取开始日。 |
| `module` | ✅ | `习思想`／`毛中特`／`史纲`／`马原`／`思法`／`当代`。 |
| `priority` | ⬜ | `S`／`A`／`B`，默认 `A`。 |
| `period` | ⬜ | 缺省由 `date` 推出月份分组；不必手写。 |
| `summary` | ⬜ | 列表摘要（截断 500 字）。 |
| `html` | ✅ | 事件正文（HTML）；`h2`/`h3` 会被自动提取为目录。 |
| `outline` | ⬜ | 目录；缺省时工具自动从 html 的 h2/h3 提取。 |
| `word_count` | ⬜ | 字数；缺省时工具自动统计。 |
| `source_file` | ⬜ | 溯源文件名。 |
| `level`／`type`／`tag` | ⬜ | 首页卡片字段；缺省走 CARD_MAP 兜底，事件条目一般不用填。 |

示例：

```json
{
  "slug": "ev-20261112-3fa2c1",
  "title": "纪念孙中山先生诞辰160周年大会",
  "date": "2026-11-12",
  "module": "史纲",
  "priority": "S",
  "summary": "习近平出席并讲话，提出三个身份定位、三个“最”、六条“一生X→就要X”，祖国统一段落为高频命题口。",
  "html": "<h2>核心事实</h2><p>……</p><h2>固定表述（必背）</h2><blockquote><p>……</p></blockquote>",
  "source_file": "孙中山诞辰160周年专题_2026.md"
}
```

## 推送（追加 / 整表替换 + 重算 manifest）

```bash
# 追加一批事件
node tools/shizheng-push.mjs --input ./事件_2026年11月.json

# 整表替换（重构时间线时用；输入即全量条目，按 date 排序写入）
node tools/shizheng-push.mjs --input ./全量事件.json --replace

# 只校验并重算 manifest（不写 dataset）
node tools/shizheng-push.mjs --check
```

工具会校验必填字段、slug/title 唯一性、`date` 格式、`module` 枚举、priority 合法；
自动补 `outline` 与 `word_count`，并把 `period` 由 `date` 推出后写入，
最后重算 `storage/dataset-manifest.json`（bytes/items/sha256/totals）。

## 入库（内容部署）

dataset 与 manifest 一起更新并部署后，在服务器仓库根目录执行：

```bash
cd /www/wwwroot/guanlan
docker compose --env-file .env.production -f compose.production.yml \
  run --rm --no-deps -T --entrypoint php api bin/hyperf.php shizheng:import
```

- 命令只写 `hotspots` 表，不动真题分析与时政预测；先跑 `DatasetManifestVerifier`，
  manifest 不一致会直接拒绝。
- 若该表曾被后台编辑过（`content_maintenance.maintained = 1`），导入按设计会被拒绝。
  **先 `mysqldump` 备份数据库**，再显式解除保护后重跑：

```bash
mysqldump ... > /www/backup/guanlan/$(date +%Y%m%d-%H%M)-hotspots.sql   # 备份
php bin/hyperf.php shizheng:import --force                              # 容器内执行
```

## 校验与排错

- **部署时 `Dataset integrity check failed`**：manifest 与文件不一致。本地跑
  `node tools/shizheng-push.mjs --check` 重算后再提交。
- **卡片落成 level=A / tag=时政**：该条没写 `module`，也没在 `ArticleSeeder::CARD_MAP` 配 period。
- **slug/title 冲突**：工具会拒绝重复；改一个唯一值。
- **同日同名事件**：标题会带「（2）」后缀，属正常去重结果。

## 备注

- 云端自动抓取（crawler / 候选池 / AI 筛选）已于 2026-10-04 拆除，不恢复。
- 2026-11 起 `/hotspots` 由「按月长文」改为「按事件的长文时间线」：旧的分月长文条目
  被同一批源材料拆出的事件条目取代，旧 slug 不再可访问。

## 时政预测（predictions.json）

预测不再按"文件"入库，而是**按题位拆分**：Q15／Q16 单选、Q32／Q33 多选、Q34—Q38 分析题，
另加一篇「命题规律总纲」。字段与热点不同：

| 字段 | 必填 | 说明 |
|---|---|---|
| `slug` | ✅ | 全库唯一，建议 `pred-2027-qNN-短哈希`。 |
| `title` | ✅ | 以题位开头，如 `Q15 单选｜国内唯一性事实`。 |
| `layer` | ✅ | 分组键：`命题规律`／`选择题`／`分析题`。 |
| `sort_order` | ✅ | 题位顺序：总纲 0，Q15/16/32/33 = 11/12/13/14，Q34—Q38 = 21—25。 |
| `priority` | ⬜ | S／A／B，用于卡片角标。 |
| `html` | ✅ | 正文；`h2`/`h3` 自动提为目录。 |

推送与入库：

```bash
node tools/shizheng-push.mjs --input ./预测.json --dataset predictions.json   # 追加
node tools/shizheng-push.mjs --input ./预测.json --dataset predictions.json --replace
# 服务器侧
docker compose ... run --rm --no-deps -T --entrypoint php api bin/hyperf.php predict:import --force
```

> `predict:import` 只重灌 `predictions` 表；`shizheng:import` 只重灌 `hotspots` 表。
> 表格清空必须用 DELETE（`DatasetReader::truncate()`），**不能写 TRUNCATE**——DDL 会隐式提交并打断导入事务。
