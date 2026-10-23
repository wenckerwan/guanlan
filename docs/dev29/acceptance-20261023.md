# DEV29 验收收尾（2026-10-23）

> 后续状态更新：用户已授权合入与部署，976f5c9 已推送 main；本轮核实生产原已运行同一版本且 ACTIVE，线上一致性和健康验收通过。详见 [合入与生产核验记录](deployment-20261023.md)。下文“未合入/未部署”保留验收阶段的状态，不代表最新交付状态。

## 结论与版本归属

DEV29 的自动回归、生产构建和隔离环境验收通过。当前是已验证的开发分支交付，尚未合入远端 main，也未部署生产。

| 项目 | 本次核对结果 |
|---|---|
| VERSION | V0.1-dev.29 |
| 已验收业务提交 | 976f5c92d38a51c78c58dfafeabe41e49ead63b8 |
| 当前工作分支 | fix/security-production-20261016 |
| 查询到的远端 main | 5ed992d5e3a4c1cc6a5e63f5a137779926f7a5ce |
| 与远端 main 比较 | ahead 2、behind 0；未合入 |
| CI | [Actions 39776344928](https://github.com/wenckerwan/guanlan/actions/runs/39776344928)，conclusion=success |
| CI 运行时间 | 2026-10-22 20:11—20:13，Asia/Singapore |
| 本次业务代码修改 | 无；修正文档中的旧测试数量、PHP 命令及验收状态 |

## 本次重新执行的本机检查

环境：Windows，Node.js v24.22.1，PHP 8.3.36，Python 为 Codex 捆绑运行时。沿用现有锁文件依赖；未修改锁文件。

### 1. 完整只读稳定性入口：通过，退出码 0

仓库根目录执行：

```powershell
& 'C:/Users/Administrator/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe' -X utf8 tools/test_stability.py --php 'C:/Users/Administrator/AppData/Local/Temp/guanlan-php-1022/php.exe'
```

实际结果：

```text
tests 95
pass 95
fail 0
AccountIdTest: PASS
AIAnalysisTest: PASS
AiOutboundPolicyTest: PASS (35 checks)
Bugs1022Test: PASS
ContentStatusTest: PASS
DatasetManifestVerifierTest: PASS
PASS SMTP TLS initialization, upgrade, delayed response and credential protection
MistakeAccessTest: PASS
MistakeImportAccessTest: PASS (including 12 controller outbound rejection cases)
PASS article authorization and email-code client IP boundaries
VisitWindowTest: PASS
Dataset integrity OK: 8 files
Ran 3 tests ... OK
Ran 11 tests ... OK
Offline stability regressions passed; browser, database integration and deployment are separate checks.
```

3 项覆盖快照和 PHP 扩展调用配置；11 项覆盖发布行为。SMTP 使用替身，不发送真实邮件。套件前后工作区指纹一致；忽略文件不在此工作区保护范围内。

### 2. 生产构建：通过，退出码 0

`apps/web` 下执行 `node node_modules/nuxt/bin/nuxt.mjs build`，实际输出 `Build complete!`。依赖仍产生 DEP0155 trailing-slash deprecation warning，不影响本次构建成功。

### 3. 本次构建的浏览器复验：通过，退出码 0

仓库根目录执行 `node tools/tests/run-dev29-browser.cjs`，脚本自行启动并关闭 `127.0.0.1:3039` 的生产预览。实际输出：

```text
PASS AI complete/partial/unsupported/401/history/sanitization
PASS history offline edit/reconnect/401/pending replay/account switch; no page errors
```

首次启动因本机缺少 Playwright 1.58.2 指定的 Chromium headless shell v1208 失败，尚未进入页面测试；使用 `node node_modules/playwright/cli.js install chromium --only-shell` 补齐后复验通过。没有改用其他版本的自动化库或修改测试断言。浏览器依赖位于用户缓存，未修改项目锁文件。

### 4. 文档与变更检查：通过

`tools/doclink.py` 检查 31 份顶层文档的 61 个相对链接，结果 OK；另行检查 `docs/dev29/*.md` 链接及保存的 CI 提交/结论，结果 PASS。`git diff --check` 通过。业务代码及锁文件无本次修改，版本保持 dev.29。

## 已核对的 CI 证据

本次通过 GitHub CLI 读取运行状态、步骤及实际日志，核对 headSha 与本机 HEAD 一致，未以 workflow 配置文件替代运行证据。

| 步骤 | 实际结果 |
|---|---|
| 锁文件安装与完整只读稳定性套件 | success；95 前端、11 PHP 入口、8 数据集、3 稳定性/快照、11 发布行为通过 |
| tools/deploy/test_config.sh | success；部署脚本及生产 Compose 校验通过 |
| Nuxt 生产构建 | success |
| 生产预览浏览器夹具 | success；AI complete/partial/unsupported/401/history/sanitization；史纲 offline/reconnect/401/pending replay/account switch；无页面错误 |
| 真实 Docker 隔离演练 | success；activate、explicit rollback、failed activation auto rollback；四个不变容器保持一致；合成备份保留 |

保留 [CI 元数据](ci-39776344928.json) 和 [关键日志摘录](ci-39776344928-summary.txt)。

首次提交 3f430db 的 CI 曾失败：全部测试使用 `php -n`，导致 Ubuntu 的 ctype 扩展被禁用，MistakeAccessTest 报 `ctype_digit()` 未定义。后续 976f5c9 仅对需要替换原生 Swoole 的 MailerTransportTest 使用 `-n`，其他入口保留已配置扩展；新增配置回归并取得上述成功运行。首次失败不属于当前提交的未关闭故障。

## 验收范围及独立待办

- AI 浏览器检查使用隔离 API 夹具，没有调用真实模型；不能视为供应商请求、模型返回、数据库报告保存的完整真实链路验收。
- PHP 用例采用测试替身；8 份数据集校验读取实际仓库文件，但不启动 MySQL。
- Docker 演练启动真实容器，但容器内业务、PHP 和 SQL 是合成夹具；未验证真实 MySQL 业务事务或数据库恢复。
- CI 和本机检查证明当前提交的隔离回归通过，不证明候选业务镜像已经构建、传送、加载或生产运行。
- 生产镜像、当前服务器 SHA、线上内容摘要和健康状态本次未查询，遵守维护期间不连接服务器、不部署的既有约束。
- 尚未执行合入 main、生产发布、真实模型调用、真实邮件发送、真实业务数据库恢复。这些不计入本次通过范围。

## 后续发布入口

1. 按仓库交付流程审阅并合入已验收分支，保留对应提交和 CI 链接。
2. 在具备生产发布条件和授权后，重新确认维护状态、目标版本、镜像和迁移兼容性。
3. 使用 [发布操作说明](release-runbook.md) 准备独立构建的 API/Web 镜像、备份和配置指纹，再执行发布和上线验收。
4. 真实 MySQL 集成、完整 AI 链路和数据库恢复分别安排独立环境、明确测试记录及调用授权，不用合成夹具结果替代。

代码和隔离验收清单已在 [task_plan.md](task_plan.md) 更新；生产发布仍单独记录。
