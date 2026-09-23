# V0.1-dev.5 数据集完整性闭环设计

## 目标

让本地资料构建、提交入库和服务器 Seeder 使用同一份可审计摘要。任何数据集缺失、内容被修改、条数不一致或来源清单变化，都必须在数据库写入前明确报错并退出非零。

本设计只读取工程目录内已经复制的学习资料，不读取、修改或删除 `F:\2027考研资料\考研政治` 中的原始文件。

## 范围

- `tools/ingest/build_all.py` 成功生成全部数据集后，原子写入 `storage/dataset-manifest.json`。
- 摘要覆盖 `storage/dataset` 下 8 个既有 JSON 文件。
- 摘要关联 `storage/import-manifest.json` 的 SHA-256 和来源统计，形成来源到派生数据的 provenance（来源链）。
- API 容器在 migration 和 Seeder 前校验摘要；校验失败时不执行数据库写入。
- Python 单元测试覆盖摘要生成、稳定排序、哈希变化、缺失文件和条数变化。
- Docker 负向冒烟覆盖“篡改一个数据集后启动失败，恢复后启动成功”。

## 非目标

- 不把 F 盘原始资料直接挂载到容器。
- 不实现增量导入；V0.1-dev.5 仍采用全量生成和幂等 Seeder。
- 不改变现有数据表结构。
- 不引入对象存储、消息队列或 Meilisearch。

## 产物格式

`storage/dataset-manifest.json` 使用以下稳定结构：

```json
{
  "schema_version": 1,
  "source_manifest": {
    "path": "storage/import-manifest.json",
    "sha256": "64 位十六进制摘要",
    "asset_count": 177,
    "logical_document_count": 151
  },
  "datasets": {
    "analysis_articles.json": {
      "bytes": 405934,
      "sha256": "64 位十六进制摘要",
      "records": 13,
      "groups": {}
    },
    "mistakes.json": {
      "bytes": 922590,
      "sha256": "64 位十六进制摘要",
      "records": 299,
      "groups": {
        "handbooks": 5,
        "items": 292,
        "students": 2
      }
    }
  },
  "totals": {
    "bytes": 4893281,
    "files": 8,
    "records": 2062
  }
}
```

顶层数组的 `records` 为数组长度。顶层对象的 `groups` 记录其中所有数组字段的长度，`records` 为这些长度之和。键名按字典序写入，不记录构建时间，确保相同输入得到字节级一致的摘要。

## 构建流程

1. `build_all.py` 删除旧的临时摘要，但保留最后一次有效正式摘要。
2. 按既有顺序执行四个构建脚本；任一步失败时停止，不覆盖正式摘要。
3. 调用 `manifest.py` 刷新 `storage/import-manifest.json`；该脚本只扫描工程目录旁的只读资料副本，并继续排除工程自身。
4. 解析全部 8 个数据集，计算条数、字节数和 SHA-256。
5. 将摘要写入同目录临时文件，完成后用原子替换更新正式摘要。
6. 控制台打印与摘要一致的逐文件结果和总计。

## 运行时校验

新增独立的 PHP 校验类 `App\Seeder\DatasetManifestVerifier`：

- 读取 `storage/dataset-manifest.json`。
- 验证 `schema_version=1` 和 8 个必需文件均存在。
- 重新计算每个文件的字节数、SHA-256、`records` 和 `groups`。
- 验证 `storage/import-manifest.json` 的 SHA-256 与来源统计。
- 收集所有差异后一次性输出，异常消息包含文件名、字段、期望值和实际值。
- 任一差异抛出 `RuntimeException`，进程退出非零。

新增 `apps/api/bin/verify-dataset.php` 作为容器入口检查。API 启动顺序调整为：

```text
verify-dataset -> migrate --force -> db:seed --force -> start
```

这样损坏的数据不会触发 migration、清表或 Seeder 写入。各数据 Seeder 仍在 `run()` 开头调用同一校验器；校验器在单进程内缓存成功结果，防止绕过容器入口直接运行 `db:seed`。

## 错误处理

- 摘要文件不存在：提示先运行 `python tools/ingest/build_all.py`。
- JSON 无法解析：指出具体文件和解析错误。
- 文件缺失：列出缺失路径。
- 哈希或条数不一致：同时显示期望值与实际值。
- 来源清单不一致：提示重新运行 `manifest.py` 和 `build_all.py`，不自动信任服务器上的变化。

所有失败均发生在数据库写入前，不采用“跳过缺失文件继续导入”的旧行为。

## 测试与验收

- Python 单元测试使用临时目录和小型 JSON，不依赖真实学习资料。
- 首次测试必须因摘要模块不存在而失败，再实现生成逻辑。
- `tools/verify.ps1` 和 `tools/verify.sh` 纳入新单元测试。
- Docker 负向冒烟在 WSL 验证副本中操作，不修改主工作区和 F 盘资料。
- 完整验收包括：Python 测试、PHP 静态检查、前端测试/构建、Docker 正常启动、篡改数据失败、恢复数据成功和现有 `smoke.sh`。

## 版本与文档

- 版本提升为 `V0.1-dev.5`。
- `README.md` 说明摘要生成与故障排查。
- `docs/development-plan.md` 将 1.4 标为已完成，并新增生产部署工作项。
- `CHANGELOG.md` 只记录实际执行过的校验结果。
