# DEV30 知识库验收

## 产品目标

自己快速查找知识、核对出处、给朋友分享具体条目；普通链接继承原有权限。新增学习进度、云同步、打卡与公开免登录分享均不属于本次范围。

## 内容映射

| 类型 | 稳定标识与地址 | 当前正文与发布来源 | 出处/修订 |
|---|---|---|---|
| 马原概念 | node.id；/mayuan/?concept=ID | mayuan_records 的 content.published.nodes | 节点 sources 的 label/locator；内容版本 contentVersion |
| 史纲事件 | event.id；/history/?event=ID | 最新 published history_datasets，经 HistoryService 过滤 | 原表/教材日期断言的 sourceId、page；数据版本及 generatedAt |
| 文章 | slug；原栏目地址 | 原文章表，排除 hidden；保留访客配额 | 原有 source_file、正文、revision；后台维护/导入保护保持 |
| 真题 | pid 与题号；/papers/PID#qN | 原 papers/questions；正文需登录 | 年份、题号、原题与解析 |

检索的最小映射保留类型、稳定ID、标题、学科、摘要、正文匹配和发布边界；没有的来源、别名或修订时间不自动补造。正文不迁移到统一新表。

## 样板查询

| 关键词 | 目标条目 | 纯检索结果 |
|---|---|---|
| 矛盾 | concept / contradiction | 第一名 |
| 实践 | concept / practice | 第一名 |
| 遵义会议 | history / p4-r055-e01 | 第一名 |
| 虎门销烟 | history / p1-r001-e01 | 第一名 |
| 五四运动 | history / p2-r038-e01 | 第一名 |

数据测试覆盖 101 个概念和 103 个独立已发布历史事件。投影及六次纯内存检索用时 5.06 ms；不包括 SQL、网络和 HTTP，不代表生产端到端延迟。

## 验证记录

- 搜索：实现及针对性验证见 [search-report.md](search-report.md)。独立审查通过，无关键或重要缺陷；密集表达式和宽泛查询内存开销作为审查观察项。
- 深链接与阅读：马原概念支持初始链接、刷新、关闭/切换、浏览器前后导航、失效 ID 提示；史纲支持事件与原表合并组直达，并修复保存筛选阻挡目标的问题。
- 分享与引用：普通地址继承原权限；史纲分享含标题/摘要，引用含记录中的日期出处及版本；马原引用含原有来源定位与版本。复制失败展示可手动选中的原文，不显示虚假成功，也不包含个人笔记。
- 阅读器：使用稳定 ID 派生的正文/出处章节锚点，马原保持已收录关系；没有逐条修订时间时明确说明，数据生成时间不冒充内容修订时间。
- 自动化验证：主站完整 Node 回归与两个 Nuxt 生产构建已执行，最终数量与浏览器结果见下方最终记录。根任务另执行 PHP 14 项（启用 assertions/mbstring）、Python 稳定性 3 项及发布工具 11 项，全通过。
- 独立代码审查发现并修复原表合并组链接目标不可见及马原章节刷新未定位问题；真实浏览器进一步暴露筛选与前后导航交互，已纳入回归。

## 发布注意

- 此轮没有生产写入、权限变更或数据迁移。
- DEV29 发布工具将 mayuan-api / mayuan-web 视为受保护的不变服务。DEV30 修改马原前端，正式发布前须准备覆盖 mayuan-web 的镜像、备份、验证和回滚方案，不能直接把 DEV29 工具运行成功当成 DEV30 全部上线。
- 当前机器未发现 Docker 命令；真实 MySQL/容器端到端验证不能用内存测试替代。


## 本地验证边界

- 浏览器脚本：`tools/tests/dev30-history-browser.cjs`、`tools/tests/dev30-mayuan-browser.cjs`，使用真实无头 Edge 打开本地生产构建，API 由仓库内容夹具拦截。马原独立预览中的主站 `/visit-tracker.js` 用空脚本替代；因此这些通过不证明实际网关、数据库、登录服务、访问统计或生产部署可用。
- 史纲浏览器验证登录跳转保留原事件地址；实际凭据登录、MySQL 数据库、PDF 文档服务及生产网络权限矩阵尚未运行。没有生产写入或发布。
- 截图位于 `output/dev30-history-mobile.png` 与 `output/dev30-mayuan-mobile.png`；构建/回归日志位于 `output/dev30-web-*.log` 与 `vendor/mayuan/apps/web/artifacts/dev30-build.log`。

## 最终回归记录（2026-10-23）

- 主站：`node --test --test-concurrency=1 tests/*.test.mjs`，108/108 通过。
- 马原：`node --test tests/*.test.mjs`，41/41 通过。
- 两个前端：`node node_modules/nuxt/bin/nuxt.mjs build`，均成功。依赖弃用提示及原有 Three.js 大块警告仍存在。
- 独立审查：修复组链接、章节定位、Back 筛选与自写 URL 回声问题后，规范符合性及代码质量复审通过，无剩余关键/重要发现。随后对浏览器发现的宿主 push/replace 并发取消与旧查询回声进行独立复审，最终亦通过。
- Mayuan 真实 Edge / API 夹具烟测通过：概念与来源章节首次打开/刷新、关闭、后退、复制被拒的手动回退、失效 ID、390px 无横向溢出；无页面脚本错误（主站统计脚本在独立预览中明确替代）。

- History 真实 Edge / API 夹具烟测：独立事件与原表合并组、刷新、后退恢复、来源章节锚点、引用复制被拒的手动回退、失效 ID、390px 无横向溢出、未登录重定向保留事件地址；浏览器发现的 push/replace 取消及自写回声问题均加入宿主适配器回归。

最终生产构建上的史纲烟测已重跑通过，包含连续输入后的过滤保持与 Back 恢复；日志 `output/dev30-history-browser.log`。截图已人工查看，两端 390px 阅读布局未见横向溢出。
