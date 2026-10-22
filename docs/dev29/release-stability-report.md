# dev.29 发布稳定性报告

日期：2026-10-22

本线只修改发布脚本、发布回归测试和离线稳定性入口；不触碰 `HistoryExplorer`。发布工具继续采用预构建镜像切换，准备阶段保留数据库快照、旧/新镜像 ID、配置摘要与不可变容器 ID；激活和回退都拒绝配置、环境、提交或备份校验漂移。激活前会确认 MySQL、Redis 与马原容器仍是准备阶段的同一组容器，回退会确认当前仍运行准备阶段记录的新镜像；任何激活失败继续自动回退旧镜像，不执行构建、拉取、`git reset/clean`、迁移或数据库恢复。

`tools/test_stability.py` 增加 `--suite release` 发布专用入口，并在所有离线套件前后比较工作区状态；若回归过程改写工作区则直接失败。该入口不生成数据集、不连接生产环境。原有 `all/frontend/backend` 入口保留。

发布覆盖层让 API 容器先执行 `php bin/verify-dataset.php`，成功后才 `exec php bin/hyperf.php start`；激活和回退共用该覆盖层，因此两条路径都会经过只读数据集门禁。隔离 Docker 演练中的 PHP 夹具也实现了该门禁。

## 覆盖的测试

| 命令 | 结果 |
|---|---|
| `python tools/test_stability.py --suite release` | 11/11 发布行为、2/2 快照回归通过 |
| `python -m unittest tools.deploy.test_release -v` | 11/11 通过 |
| `python tools/test_stability.py --suite frontend --node <bundled-node>` | 95/95 Node 测试通过 |
| `python -m py_compile tools/deploy/release.py tools/deploy/test_release.py tools/test_stability.py` | 通过 |
| `git diff --check -- tools/deploy/release.py tools/deploy/test_release.py tools/test_stability.py` | 通过 |
| `python -m unittest tools.deploy.test_release tools.tests.test_stability -v` | 13/13 通过 |

稳定性快照包含 Git 状态、所有已跟踪文件和所有未忽略未跟踪文件的模式、大小与 SHA-256 内容指纹；因此已处于 dirty 状态的文件被原地改写也会被发现，忽略文件变化则不会造成误报；`.env.production`、`storage/raw/`、`storage/previews/`和部署备份等 Git 忽略路径不在此只读快照范围内。

发布行为测试覆盖成功激活、显式回退、激活失败自动回退、缺失候选镜像、配置/环境/只读数据集漂移、重复或不安全 release ID、不可变容器变化、提交漂移、回退失败和数据备份保留。测试使用状态化 Docker 适配器，不需要 Docker daemon，也不触碰真实数据库。后端/all 稳定性入口还会调用 `php -n apps/api/bin/verify-dataset.php <repo-root>`，对提交的实际数据集执行只读校验；当前机器无 PHP，因此该分支在本机未执行。

当前机器没有可用的 PHP、Docker，因此本报告未宣称 PHP 集成测试或真实 Docker 演练完成；它们仍由独立环境按 `tools/deploy/rehearse_release.py` 和 CI 入口执行。未运行的生产部署也不计入本报告。
