# 时政热点 · 本地推送流程

> 时政内容采用 **seed 式数据管**：云端**不自动抓取**，由你在本地抓取、审核、
> 整理成长文后，追加进数据集文件，随部署入库。质量由你把关。

## 架构

```
本地抓取 → 你审核/编辑 → 追加到 storage/dataset/hotspots.json
        → 重算 storage/dataset-manifest.json → git 提交部署
        → api 启动时 DatasetReader::list('hotspots.json') 灌库（ArticleSeeder::seedHotspots）
```

- 表结构：`hotspots`（基础字段 + 长文 slug/period/priority/html/outline/... + status/comment_mode）。
- 入库即发布：`status = published`（本地审核过才入库，无云端筛选态）。
- seed 幂等：每次部署清空 `whereNotNull('slug')` 的旧长文后整灌，按 slug 唯一。
- 前台：`/hotspots`（按 period 分组，游客限免 3 篇）+ `/hotspots/{slug}` 详情。

## 快速上手

### 1. 准备一期时政（本地审核完）

新建一个 JSON 文件，例如 `时政_2026年10月.json`：

```json
{
  "slug": "2026-10-f4a1c2d3",
  "title": "时政考点·2026年10月",
  "period": "2026年10月",
  "priority": "S",
  "summary": "本月主线：二十届五中全会召开……",
  "html": "<h2>一、本月主线速览</h2><p>……</p><h3>1.1 ……</h3>",
  "source_file": "时政考点_2026年10月.md"
}
```

字段说明：

| 字段 | 必填 | 说明 |
|---|---|---|
| `slug` | ✅ | 全库唯一。建议 `期-日期-短哈希`，如 `2026-10-f4a1c2d3`。 |
| `title` | ✅ | 全库唯一。 |
| `period` | ✅ | 分组键，如 `2026年10月`。**新增期需补 CARD_MAP（见第 3 步）**。 |
| `html` | ✅ | 长文正文（HTML）。`h2`/`h3` 会被自动提取为目录。 |
| `priority` | ⬜ | `S`/`A`/`B`，默认 `A`。 |
| `summary` | ⬜ | 列表摘要（截断 500 字）。 |
| `outline` | ⬜ | 目录；**缺省时工具自动从 html 的 h2/h3 提取**。 |
| `word_count` | ⬜ | 字数；**缺省时工具自动统计 html 纯文本字数**。 |
| `source_file` | ⬜ | 溯源文件名（本地 md）。 |

一次推多期：把多个对象放进一个 JSON **数组**。

### 2. 推送（追加 + 重算 manifest）

在仓库根目录运行：

```bash
node tools/shizheng-push.mjs --input ./时政_2026年10月.json
```

工具会：
- 校验必填字段、slug/title 唯一性、priority 合法；
- 自动补 outline（若无）与 word_count（若无）；
- 追加到 `storage/dataset/hotspots.json`；
- 重算 `storage/dataset-manifest.json`（bytes/items/sha256/totals）；
- **若检测到新 period，打印需要补的 `CARD_MAP` 行**。

只校验 / 重算 manifest（不追加）：

```bash
node tools/shizheng-push.mjs --check
```

### 3. 新 period → 补 CARD_MAP（仅新增月份时）

`apps/api/seeders/ArticleSeeder.php` 的 `CARD_MAP` 按 `period` 决定首页卡片的
学科/level/type/tag。**未配置的 period 会落 fallback**（level=A/type=形势与政策/tag=时政）。
把工具打印的那行加进 `CARD_MAP`，`tag` 起一个本期标签：

```php
'2026年10月' => ['slug' => 'xi-jinping-thought', 'level' => 'S', 'type' => '理论与政策', 'tag' => '五中全会'],
```

### 4. 提交部署

```bash
git add storage/dataset/hotspots.json storage/dataset-manifest.json apps/api/seeders/ArticleSeeder.php
git commit -m "content(shizheng): 时政考点 2026年10月"
git push origin dev main
# 服务器部署后 api 重启即自动 seed（DatasetManifestVerifier 校验 → ArticleSeeder 灌库）
```

部署后访问 `https://guanlan.wencker.top/hotspots` 确认新期出现。

## 校验与排错

- **部署时 `Dataset integrity check failed`**：manifest 与文件不一致。本地跑
  `node tools/shizheng-push.mjs --check` 重算后再提交。
- **新期显示成 level=A / tag=时政**：该 period 未在 `CARD_MAP` 配置，回第 3 步补。
- **slug/title 冲突**：工具会拒绝重复；改一个唯一值。

## 备注

- 云端自动抓取（crawler / 候选池 / AI 筛选）已于 2026-10-04 拆除，不恢复；本流程是唯一时政入库途径。
- `hotspots` 表还保留了 dev.2 的「首页卡片」字段（level/summary/type/tag），由 CARD_MAP 在 seed 时写入。
- 修改/下线某期：直接编辑 `hotspots.json`（删/改对应条目），跑 `--check` 重算 manifest，提交部署即可。
