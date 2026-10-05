# 更新记录

## SEC-02 AI 报告安全渲染 - 2026-10-05

- 新增共享 `renderReportMarkdown()`，使用 sanitize-html 白名单清洗 marked 输出；新报告、SSE 增量与历史报告均接入，清洗异常回退转义纯文本。
- 报告保留标题、列表、表格、代码块和 http/https/mailto 链接；移除图片与嵌入资源、事件属性、危险协议和协议相对链接，避免报告自动加载外部追踪资源。原始 Markdown 下载保持原样，下载文件仍属于未清洗的原始文本。
- 新增 5 项回归：危险 HTML、链接协议、正常 Markdown、所有流式前缀、空报告。48 项前端测试通过。
- 浏览器检查共享渲染器输出：脚本未执行，危险 DOM 为 0，中文标题及表格正常；这是渲染产物验证，不等于上传—模型请求—保存全流程验证。
- npm 安装审计报告仍有 11 项依赖问题（1 low、10 high），未在本次执行破坏性自动升级。代码提交 `b8a0196` 已推送到 origin/dev 并部署，仅重建 web。所有容器健康，线上首页、健康接口、马原入口、公共 A 读取为 200，未登录账号接口为 401；公开前端资源与容器资源 SHA-256 一致。保留旧 web 镜像作为回滚点；完整 AI 流程验收仍待完成。

## SEC-01 公共错题模板写权限 - 2026-10-05

- 新增 `MistakeAccess::canWriteStudent()`，将错题读取权限与写入权限分离：公开 A 仅管理员可写，私有错题仅所属账号或管理员可写。
- `requestAIAnalysis` 与 `requestAIAnalysisStream` 在配置校验、AI 抽取和 SSE 之前拒绝无导入权限的请求；`MistakeService::importUploadedItems()` 增加服务层防线，防止绕过控制器写入。
- 新增 `apps/api/tests/MistakeImportAccessTest.php`，覆盖普通用户对 A 的 SSE/非流式导入拒绝、游客拒绝、所有者/管理员继续进入流程、直接服务调用拒绝及无数据库访问。
- 验证：便携 PHP 8.3 执行 `MistakeAccessTest.php`、`MistakeImportAccessTest.php`、`AccountIdTest.php`、`DatasetManifestVerifierTest.php`、`StudyIntegrationTest.php`、`ContentStatusTest.php`、`AdminCredentialsTest.php` 均通过；`phpcheck.py` 通过。目标容器与生产 HTTP 多账号验收仍待执行。

## 部署同步 - 2026-10-03

- 服务器 `/www/wwwroot/guanlan` 的 `dev` 分支与 GitHub `origin/dev` 同步（`0dff862` → `b10dab1`）：仅新增 `services/shizheng-crawler/deploy/export_shizheng_candidates.sh`（云端候选池 TSV 导出脚本），不改运行代码，`--ff-only` 合并，生产容器未重启，健康检查 `/api/v1/health` 与 `/` 均 200。
- 为服务器 `root-189` 在 `wenckerwan/guanlan` 仓库配置了只读 deploy key（SSH 拉取），解决其无法从 GitHub 拉取的问题。

## V0.1-dev.27 - 时政筛选引入「真题相关度」+ AI 降级不再静默 - 2026-10-02

依据《观澜·每日时政筛选 云端调整方案（v1）》。抓取与发布链路本身没问题，问题在筛选质量。

### P0 · AI 静默降级暴露

- **实测结论**：`POST /admin/shizheng/config/test` 直连 DeepSeek 正常（`{"ok":true,"reply":"正常"}`），且 2026-09-30 干跑 `screen` 得到 `fallback=false` —— 说明 **AI 路径本身可用，09-30 那次是偶发失败被 `screen()` 吞掉了**（原因至今无法追溯，因为没有留痕）。这正是必须修的部分。
- `screen()` 现返回 `fallbackReason`：`no_key` / `ai_request_failed` / `ai_unparseable` / null；`isUnparseable()` 区分「AI 没答上来」与「答了但解析不出」，前者查网络与出网，后者查提示词与格式。
- 每次降级写 `logger('shizheng')->warning(...)`（带 date/provider/model/error 前 300 字）；控制器同时把 `fallbackReason` 写进 `shizheng.screen` 审计明细。
- 后台筛选结果行把兜底原因写明（「规则兜底：AI 请求失败」而不是笼统的「AI 不可用」）。

### P1 · 真题相似度打分器

- 新增 `App\Service\ShizhengSimilarityService`：**中文字符 bigram TF-IDF + 余弦相似度**，纯本地、零外部依赖、不出网、无 embedding。
  - 语料 = `questions` 表时政题池（`super_name ∈ {习思想与形策, 形势与政策以及当代世界经济与政治}`，实测 208+108=**316 题**，与方案口径一致）；每题文本 = `stem + material + options + kaodian + trap + analysis`。
  - 候选文本 = `title×2 + facts×1 + fixed_phrases×2 + exam_points×2 + traps×1 + _keywords×3`（权重沿用离线脚本）。
  - tf 取 sublinear `1+log(tf)`，idf 取 `log(1+N/df)`，两侧 L2 归一化；题池未出现的 gram 直接丢弃以保持稀疏。
  - 打分走**倒排 posting 累加**而非 316 次全向量比较。
  - 索引按题池指纹（`COUNT(*) + MAX(updated_at)`）做**进程内静态缓存**（本站无 Redis；题不常变，跨请求命中）。
- 迁移 `2026_10_02_000001_add_exam_similarity_to_shizheng_candidates`：`exam_sim`/`exam_affinity`（均 `decimal(5,4)` 默认 0）+ `exam_matches`(json) + `(publish_date, exam_sim)` 索引；down() 先删索引再删列，完整可回滚。存量行有默认值，不阻断。
- `upsertCandidates()` 内逐条算好落列——**爬虫侧一行代码没动**，题库与相似度都留在服务端。
- 实测代价（api 容器内、真实 316 题）：索引构建 + 51 条打分 **0.25 秒**，常驻内存增量 **42 MB**，峰值 52 MB。
- 新增命令 `php bin/hyperf.php shizheng:rescore [date]` 回填/刷新历史候选的三列（在 `config/autoload/commands.php` 显式注册，沿用本仓库既有的注册方式）。

### P2 · AI brief 补证据

- brief 每条增 `exam_points`/`traps`/`keywords`/`exam_sim`/`exam_top_questions`（后 3 个字段此前完全缺失，AI 只能凭通识排序）。
- system prompt 增第 4 条标准：与历年时政真题考点重合度高者显著加权，纯地方/行业/数据型软新闻即便原始 priority 高也应靠后；并要求 `reason` 末尾注明「真题相关 x / 年份·模块」便于人工复核。
- `selected[]` 回填本地算出的 `sim`（不信 AI 自报的分数）。temperature 维持 0.2。

### P3 · 兜底混合排序 + 可观测 + 后台列

- `screenByRule()` 主键改为 **`exam_sim` 降序**，同分再看爬虫 `payload.priority`；新增 `sim_only`（只看相似度）。此前只按 priority 排，而爬虫规则模式下**所有条目 priority 都是「中」**，等于随机——这就是 09-30 漏选/误发的直接原因。
- `screen` 增 `strategy` 参数（`ai|hybrid|sim_only`，默认 `ai`）；显式指定后两种视为「操作者选择」，`fallback=false`，不记降级。
- `candidates()` 返回 `examSim`/`examAffinity`/`examMatches`；后台 `/admin/shizheng` 候选行新增「真题相关度」列（绝对分数 + 命中年份与题干摘要）。
- **分层标签改用当日候选池分位**（前 25% 强 / 后 25% 弱），不写死 0.12/0.08：方案里的阈值是在离线脚本口径上量的，本实现同一条素材分数系统性偏高约 1.15 倍（#403 本实现 0.124 vs 离线 0.076），照抄固定阈值会把「不该发」的条目显示成「强相关」。绝对分数照常展示供跨天比较，排序与兜底仍以绝对值为准。

### 验收回放（2026-09-30 的 51 条候选，`deploy/check_exam_replay.py`，全程 auto=false 不发布）

**以下为 dev.27 部署到 root-189 之后的真实结果**（`shizheng:rescore 2026-09-30` 回填 51 条耗时 1.1 秒）：

- 相似度落列：#413 党建思想研讨会 **0.2174** / #427 文化赋能 0.1701 / #410 中日四个政治文件 0.1470 / #415 高水平安全护航 0.1368；#403 央行货币政策工具 0.1238、#404 增值税留抵退税 0.0835。四条应选全部高于两条不该发的。
- `sim_only`、`hybrid`、`ai` 三种策略各跑一遍：**6/6 判定全通过**（四条应进全部进 top10、两条应出全部跌出），`ai` 策略 `fallback=false`。方案 §3.1 达成。
- 当天池 max 分布：min 0.051 / p25 0.076 / 中位 0.105 / max 0.217。
- **P0 实测（§3.3）**：把 baseUrl 临时改成不可达域名后干跑筛选，返回 `fallback=true, fallbackReason=ai_request_failed`；容器日志出现 `shizheng.WARNING: 时政 AI 筛选降级为规则兜底 {"date":"2026-09-30","fallback_reason":"ai_request_failed","error":"AI 请求失败: Failed to connecting to ... DNS Lookup resolve failed","provider":"deepseek","model":"deepseek-chat"}`；审计写入 `{"top":10,"auto":false,"strategy":"ai","fallbackReason":"ai_request_failed","selected":10}`。跑完原样还原 baseUrl 并复测 `config/test` 返回 `{"ok":true,"reply":"正常"}`。对照记录：还原后的正常筛选审计为 `fallbackReason: null`。
- **推送路径自查（`deploy/check_push_path_scores.py`）**：挑一条候选把 `exam_sim/exam_affinity/exam_matches` 清零后用原 payload 单条重推 → 返回 201 `{created:0,updated:1}`，三列重新算出 `sim=0.0811 / affinity=0.0590 / matches 481 字节`。证明相似度由 `upsertCandidates()` 就地计算，不依赖 rescore。
- 全量回填：`shizheng:rescore`（无参数）重算 401 条候选，12.0 秒；2026-09-29 / 09-30 / 10-01 三个日期的 scored 均等于条数。
- 回归：`publish()` 标题去重跳过、`upsertCandidates()` 不回写 `published` 行、`ai_*`/`hotspot_id` 不受重推影响 —— 逻辑未改动；站点首页与 `/admin/shizheng` 均 200，公开接口正常。


### 测试

- 新增 `apps/api/tests/ShizhengSimilarityTest.php`（纯算法，不依赖容器与数据库，`php tests/ShizhengSimilarityTest.php`）：bigram 数量与切分边界（标点不参与、全角/大小写归一、单字不成组）、候选文本字段权重、IDF 单调性（全池常见 gram < 罕见 gram）、主题命中排序、同文本余弦=1、空向量安全。**容器内 PHP 8.3 跑通 PASS**。
- 绝对阈值分层不做单测（依赖真实题池），改由 `check_exam_replay.py` 拿历史数据回放断言。

### 上线后浏览器实测的观感修正（共四处，后两处是改完再看一眼才发现的）

在登录态浏览器里看了 `/admin/shizheng` 的真实渲染（09-30 的 51 条），色块与分数都正常，但暴露出四个问题（后两个是改完再看一眼才发现的）：

- **命中摘要硬切出半截词并撑坏行**：`slice(0,16)` 会产出「中国人民解放军战区成立大会于20」「2023年5月29日，习近平总书」这种断法，两条连排一行后又被折成 `｜ 命 / 于20`。根因是 `.record-list li` 是**不换行的 flex**，长文本被塞进同一个 flex 项里自己折行。现在摘要改 `clipExcerpt()` 按句读边界（。；，、：）收尾、最多 26 字，相近真题**独占一行**（`.exam-hits { flex:1 0 100% }` + li 允许 wrap），最多列 3 条并各带命中分。
- **列表按 id 排，相关度列上下乱跳**（0.124 → 0.182 → 0.120 → 0.100 → 0.114），人工扫选时看不出高低。`candidates()` 加 `sort` 参数（`id` 默认 | `sim` 按 `exam_sim` 降序、同分按 id），后台加「列表排序」下拉。**默认仍是 id**：筛选返回的 `selected[].index` 是按 id 序算的下标，改默认序会错位。
- **同分不同档**：两条都显示 `0.076`，一条判「中相关」一条判「弱相关」——`exam_sim` 存 4 位小数（0.0762 / 0.0759），而当日 p25 阈值正好卡在两者中间。分档前先取到 3 位小数（`r3()`），保证「看到的数字」和「拿到的标签」一致。
- **相近真题重复**：`扎根上海 向新而行` 的命中里同一条「1999·欧盟特别首脑会议」出现两次（题池存在同干异题），按摘要去重后再取前 3 条。
- 复核方式：登录后读页面 DOM 的 51 行断言 —— 降序成立、`同分不同档` 为空数组、`命中重复的行` 为空数组。

### 打分器的已知局限（不改，只记录）

字符 bigram 重合度对「纪念活动类」考点识别偏弱：实测 `纪念中国工农红军长征胜利90周年两大长征主题展览开幕` 只有 **0.051（判弱相关）**，而长征是史纲必考；同类的 `孙中山与华侨华人学术研讨会` 0.084、`纪念邹家华同志诞辰100周年座谈会` 0.120 也偏低。原因是题池里这类题少且表述不同，文本重合压不出分——**它量的是"像不像真题的措辞"，不是"命不命中考点"**。

因此：相关度**不能单独当发布依据**。默认 `strategy=ai` 下它只是喂给模型的一条证据，AI 的「命题概率」标准仍负责这类判断；只有降级到 `sim_only`/`hybrid` 时才由它主导，那种场景下要留意把纪念活动类条目排到后面。日后要修得靠语义级相似度（方案 §4 已明确另立项，本次不做）。

### 部署踩坑（本次实测，代价是站点短暂 502）

- `ShizhengRescoreCandidatesCommand::configure()` 里 `addArgument('date', 0, ...)` 传了字面量 `0`，Symfony Console 只接受 `InputArgument::OPTIONAL`（=2），于是抛 `Argument mode "0" is not valid`。**关键在于 Hyperf 的 `ApplicationFactory` 会在启动阶段实例化所有注册命令**——命令参数写错不是「这条命令不能用」，而是 **整个 api 容器起不来**：容器 CMD 里的 `until php bin/hyperf.php migrate` 永远失败 → healthcheck 不过 → web 依赖 api 不被拉起 → 站点 502。已改为常量并在代码里留了注释说明这条因果。
- 由此得到的部署纪律：**新增/改动控制台命令后，先 `docker exec guanlan-api-1 php bin/hyperf.php list` 看能否列出该命令且无 Fatal，再判定镜像可交付**；`up -d --build` 失败时 `docker compose up -d web` 可单独恢复前台。


## V0.1-dev.26 - 时政爬虫提速（仅 services/shizheng-crawler，无需重建 api/web 容器）- 2026-09-30

### 变更

- **限速改为按主机读 robots.txt**：`Crawl-delay` 本就是 per-host 指令，此前代码对所有 `*.people.com.cn` 一律等 120 秒属过度保守。现在抓取前懒加载并缓存该主机的 `robots.txt`：声明了就严格遵守（实测 `www.people.com.cn` / `www.people.cn` = 120 秒，sitemap 请求照此等待），未声明（7 个文章子域 robots 404；culture/society 有 robots 但无该指令）则用保守默认 `DEFAULT_DELAY=12s`（`CRAWL_DEFAULT_DELAY` 可覆盖）+ 0~30% 抖动。仍串行不并发。
- **sitemap 频道去重**：`sitemap_index.xml` 把 legal 列了两次，旧逻辑会把整个频道白跑一遍（约 2 小时）。`list_sitemaps()` 现按 URL 去重，实测 9 个频道各一次。
- **跨频道 URL 去重**：同一篇稿子出现在多个频道 sitemap 时只抓一次（进程内 `seen_urls`），叠加原有 `db.seen()` 落库去重。
- **重试不再重走完整限速等待**：只在首次尝试时按主机限速，重试仅退避（`RETRY_BACKOFF`），一个失效 URL 不再最多烧掉 3×delay（旧口径 6 分钟）。
- **`limit_per_channel` 可配置**：默认仍 60，改由 `LIMIT_PER_CHANNEL` 环境变量控制。
- **可观测性**：每频道结束打印「新增 N 篇，耗时 X 分钟」，每主机首次限速判定时打印生效口径。
- **预计效果**：整轮 daily 抓取从约 20 小时降到约 2.5~3 小时（人民日报 26 分钟 + sitemap 请求 20 分钟 + 文章抓取约 2 小时），不再与次日 03:30 的 cron 撞车。

### 数据质量修复（同日追加，源于补抓 09-29 时的实测排查）

- **正文提取重写**（新增 `src/core/htmltext.py`，rmrb / people_sitemap 共用）：旧的 `<div class="rm_txt_con[^"]*"[^>]*>(.*?)</div>` 是**非贪婪**匹配，遇到正文里嵌套的 `<div class="bza">` 就在第一个 `</div>` 处截断，正文变 0 字后被 `len(body) < 60` 静默丢弃 —— 09-29 那轮 politics / world / society 三个频道**整轮 0 篇入库**且不报错。现改为 `<div>`/`</div>` 深度配平扫描（`match_div_inner`），容器优先级 `ozoom` → `rm_txt_zw` → `rm_txt_con`，全失败再退回整页 `<p>` 扫描。
- **JS 不再混进正文**：人民网正文 `<p>` 里嵌了 `<script>showPlayer({...})</script>`，旧逻辑把播放器 JS 当正文存库（culture 频道已中招）。现在提取前先剥 `script/style/noscript` 与 HTML 注释。
- **编码乱码根治**：旧 fetcher 用 `r.apparent_encoding`（chardet）解码，人民日报**版面页**文字稀疏被猜成 MacCyrillic，标题变成 `еЕіиЊєеѓМж∞С...`（09-29 库里 75 条中 10 条中招）。现在 `fetcher.get()` 改走 `htmltext.decode_html(content, declared_charset(...), apparent_encoding)`：优先 HTTP 头 / `<meta charset>` / XML 声明，再依次严格尝试 chardet 猜测、utf-8、gb18030，取第一个「含中文且无替换字符」的结果；`fix_mojibake()` 作为兜底自愈（按 mac-cyrillic / cp1251 / cp1252 / latin-1 回编码再按 UTF-8 解，只有解出中文且乱码特征消失才采纳）。
- **历史数据就地修复**：`deploy/repair_mojibake.py`（默认 dry-run，`--apply` 才写库）已修复库里 10 条乱码标题，例如 `еЕіиЊєеѓМж∞СзЪДдЄЙйЗНеКЯе§Ђ...` → 《兴边富民的三重功夫（前沿观察）》；正文无一条受影响。修库前已备份 `data/crawl.db.bak-20260930-2215`。
- **假稿过滤**：删除 3 条「本版责编：×××」版权行假稿（正文 170+ 字但几乎全是 URL），并补两层防护——`list_edition()` 直接跳过责编行（旧规则因标题是乱码而失效），入库前要求正文汉字数 ≥ `MIN_BODY_CJK`（默认 30，可环境变量覆盖），新增 `htmltext.cjk_count()`。
- **标题去站点后缀**：人民网 `<title>` 形如「×××--时政--人民网」「×××--教育--人民网」「×××-理论-中国共产党新闻网」，频道段是任意词不能写死，故按「1~2 个短分隔段（≤12 字）+ 已知站名 + 行尾」锚定剥离，剥完仍 ≥4 字才采用；分隔符只认半角 `-`/`--`，因为全角竖线「｜」「丨」常出现在真标题内部（实测「原来你是这样的人大代表｜灭火英雄跨界守护文化根脉--2024年全国两会--人民网」，把竖线当分隔符会连副标题一起剥掉）。新增 `people_sitemap._clean_title()`，历史数据由 `deploy/clean_title_suffix.py` 拉齐（默认 dry-run）。
- **版式残留不再当正文**：人民网/报纸版页面里「2025年03月07日14:51 来源：人民网」「来源：光明日报」「责编：×××」「2026年09月29日 第01版」这类日期来源行此前会被当成正文首段（实测 people 库 533 篇里 59 篇中招），进而污染下游 AI 摘要（已发布的热点 id=322 摘要就以「2026年09月28日08:35 来源：光明日报222」开头）。新增 `htmltext.is_boilerplate()`，`paragraphs()` 抽取时跳过。判定要求「短 + 带时:分或来源/责编」，避免误杀「2025年9月29日，中共中央政治局召开会议……」这种真正的文首句。
- **页脚不再当正文**：页面没有正文容器时走整页 `<p>` 兜底，会把人民网页脚（社概况链接堆、许可证号、举报电话、带空格的版权行）扫进正文——实测 edu 频道「每日一闻/每日一句」6 篇的正文**全部 521 字都是页脚**，而 `len(body) < 60` 与 `MIN_BODY_CJK=30` 两道闸都拦不住（页脚本身就有 211 个汉字）。新增 `htmltext.is_footer()` / `RE_FOOTER`（高特异度串，谈「版权」「许可证」的正规稿件不会误判），`paragraphs()` 一并过滤。
- **候选推送分批，绕开网关 413**：一次性 POST 337 条候选超过 nginx `client_max_body_size`（约 1 MB），网关直接返回 **HTTP 413**，而且 `pipeline.py` 只把异常打到日志里，容易被误判成「跑成功了」。`push_hotspots.push_candidates()` 现按 `CANDIDATE_BATCH=40` 分批推送并累加 created/updated/skipped。
- **`pipeline.py --no-screen`**：只刷新候选池、绝不触发筛选与发布。用于「正文修好后想把候选池重推一遍」这种场景——服务端 `upsertCandidates()` 只写 source/channel/url/payload，不动 `ai_priority`/`ai_reason`/`hotspot_id`，且 `status='published'` 的行保持原状，所以重推是安全的（实测 337 条全部 updated、已发布 10 条状态不变）。该标志同时会跳过 `--legacy` 的本地直推，语义是「本次不发布任何内容」。
- **历史正文就地重提**：新增 `deploy/repair_body_from_raw.py`（默认 dry-run，`--apply` 才写库）——用 `raw_path` 存档的原始 HTML 按新规则重提正文，只更新 `body` 一列。判据是「旧正文命中 JS 特征、或**任一行**是版式残留、或残留页脚」+「存档还在」+「新正文三类问题都没有且汉字数 ≥ `MIN_BODY_CJK`」。逐行扫描而非只看首行，因为实测 id=172 的日期来源行夹在正文中间。两轮共修复 **61 篇**（含 id=86 的 `showPlayer` JS 污染），修前备份 `data/crawl.db.bak-beforebody-20261001-0127`，修后质量扫描 JS 特征 0 篇、异常 0 篇。配套 `deploy/diff_body.py <id>` 只读逐段对比：本次减少的字符全部是页脚噪声，真内容一句没丢。
- **垃圾行删除**：新增 `deploy/delete_junk_articles.py`（默认 dry-run，`--apply` 先导出整行到 `data/deleted_articles_<ts>.json` 再删）。判据三条同时满足：正文每一行都是页脚/版式残留、按现行规则重提得到 0 个汉字、存档 HTML 存在。实测删除上述 6 篇 edu「每日一闻/每日一句」空壳稿，2026-09-29 由 605 篇收敛到 **599 篇有效稿件**。这 6 条从未进入网站候选池（被相关性过滤挡掉了），已用 `deploy/run_delete_junk_candidates.sh` 核验——该脚本备份行数为 0 时会**主动中止**而不是继续删。
- **候选 upsert 以标题为键，洗标题会产生重复行**：`upsertCandidates()` 用 `(publish_date, title)` 定位，所以标题被 `_clean_title()` 洗过之后重推，会**新建**一行干净标题的候选，旧的脏标题行留在池子里（实测 id=321「向新而生--文化--人民网」与 id=394「向新而生」并存）。新增 `deploy/check_candidate_orphans.py`（只读，比对网站候选标题与爬虫库标题，报孤儿与同标题重复），配合 `deploy/run_delete_orphan_candidate.sh`（备份为空则**主动中止**）清掉了这一行；09-29 候选池收敛为 294 行 / 孤儿 0。
- **只读核查工具**：`deploy/check_site_titles.py`（网站侧标题乱码/后缀扫描）、`deploy/check_site_noise_breakdown.sh`（候选/热点页脚噪声总量 + 按特征分解）、`deploy/check_hotspot_summary_head.sh`、`deploy/inspect_articles.py`（打印某几篇的旧正文全文与存档 `<p>` 段）、`deploy/check_candidate_orphans.py`。均不写库，且 MySQL 口令一律从容器自身的 `$MYSQL_ROOT_PASSWORD` 读取（此前有一版把生产口令硬编码进了脚本，已改写并 amend 掉未推送的提交）；容器内 mysql 客户端必须带 `--default-character-set=utf8mb4`，否则中文全部返回 `?`。

### 测试

- 新增 `tests/test_fetcher_delay.py`（10 项离线单测）：Crawl-delay 解析（含行内注释、只对 `User-agent: *` 生效）、robots 每主机只读一次、声明值优先、`paper.people.com.cn` 固定 12s 且不查 robots、sitemap 去重与频道白名单过滤。
- 新增 `tests/test_htmltext.py`（38 项离线单测）：嵌套 `<div>` 不被截断（回归 0 篇 bug）、script 不进正文、责编/版权块被排除、`ozoom` 容器、整页兜底、div 配平与截断退化；乱码自愈覆盖 mac-cyrillic / latin-1 / cp1251 无损回解、**库里真实样本 id=64**、`errors='replace'` 丢字节时不崩不误改；`decode_html` 声明优先于 chardet、gb18030 页、自愈、空输入；`declared_charset` 头/meta/缺失；`cjk_count` 与链接堆假稿回归；`is_boilerplate` 命中 8 种版式行且**不误杀** 5 种真文首句（含「2025年9月29日，中共中央政治局召开会议……」）；`is_footer` 命中 6 种真实页脚行、不误判谈版权/许可证的正规稿件、整页兜底路径不带页脚（回归那 6 篇空壳稿）；`_clean_title` 双横线/单横线后缀、短标题保留、正常标题不动、乱码标题修复。本地与服务器 venv 均 **48 项全绿**（含 test_fetcher_delay 10 项）。
- 服务器 `deploy/probe_delay.py` 只读探针实测：`www.people.cn -> 120s`、`finance/culture/theory/paper.people.com.cn -> 12s`、sitemap 去重后 9 个频道、pipeline 依赖（`src.core.db`、`src.config`）导入正常。
- 服务器 `deploy/smoke_fetch.py` 真实抓取冒烟（`politics.people.com.cn`）：限速口径 `-> 默认 12s`，两次请求发起间隔 12.7s（≥12s 且远小于旧的 120s），**正文提取长度由修复前的 0 变为 77 字**（此前正是这里断言失败暴露了截断 bug），`SMOKE_OK`。

### 09-29 补抓与线上善后（2026-10-01 凌晨）

- **补抓结果**：2026-09-29 从 143 篇恢复到 605 篇，剔除 6 篇页脚空壳稿后为 **599 篇有效稿件**，九个频道全部有数据（politics 56 / world 60 / society 57 / culture·env·finance·legal·opinion·theory 各 60），此前 politics / world / society 因正文截断 bug 为 0。整轮 00:28:37 结束（日志标记「本次新增合计 462 篇」），早于 03:30 的 cron。
- **线上重发**：先前一次 pipeline 在抓取尚未跑完时被误触发，用不完整数据发布了 10 条热点。已用备份过的事务脚本 `deploy/rollback_premature_publish_20260930.sql` 回滚（删 2026-09-29 的 98 条候选 + hotspots id 306-315，保留人工录入的 302-305），再用完整数据重跑 `pipeline.py --date 2026-09-29`，服务端 AI 筛选发布 hotspots **316-325**，公开接口已验证。
- **已发布内容的最后一处噪声**：hotspots id=322（以法治力量筑牢民族团结进步根基）的 summary 与 html 里嵌着「2026年09月28日08:35 来源：光明日报222」——它是 AI 从未过滤的正文里摘出来的。用 `deploy/fix_hotspot_322_dateline.sql`（`REGEXP_REPLACE`，跑前 mysqldump 备份该行到 `deploy/backup_hotspot_322_*.sql`）就地剥掉，`<strong>来源：</strong>people theory` 这类正常元数据行不受影响。修完 10 条已发布热点噪声计数为 0。
- **候选池重推**：正文修好后用 `pipeline.py --date 2026-09-29 --no-screen` 把 337 条候选重推两遍，payload 里的日期来源行由 38 条降到 2 条（剩下的 2 条是「数据来源：科技部等 制图：蔡华伟」这种正当的图表署名，不是噪声），已发布 10 条的 status 与 ai_* 结果未被触碰；末态 294 行（284 pending + 10 published）。
- **教训（重要）**：`pkill -f 'shizheng-daily.sh'` 经 ssh 执行时，模式串会匹配到承载它的那条 ssh 命令自身，导致会话被杀（exit 255）而目标 wrapper 存活——正是这次误发布的直接原因。以后停远端任务一律先 `pgrep -af` 看清 PID 再逐个 `kill`，且模式串不要出现在调用命令里。
- **重跑须知**：`pipeline.py --no-push` 仍会 `db.mark_refined()`，同一日期第二次跑会得到「候选 0 条」；重跑前需 `UPDATE articles SET refined=0 WHERE publish_date='<date>'`。另外 `--date` 默认是「今天减一天」，跨零点后跑昨天的数据必须显式传日期。

## V0.1-dev.25 - 上传错题自动加入错题本 - 2026-09-30

### 变更

- **上传错题自动入错题本**：分析页上传 Markdown 点「开始分析」后，服务端先把上传内容解析为结构化错题条目导入当前考生错题本，再进入 AI 分析流。导入失败不影响分析主流程。
- **解析层双通道**：
  - AI 结构化抽取（主通道）：复用用户配置的 AI 供应商，让模型把自由格式错题 Markdown 输出为固定 JSON（module/stem/options/myAnswer/correctAnswer/kaodian），`AIAnalysisService::extractItems()`；缺少题干或正确答案的条目跳过。
  - 规则解析（兜底）：AI 未配置/未安装（hyperf/guzzle 缺失）/抽取失败时，按分析页格式示例（`## 模块 - 来源`、`**题干**`、`A.` 选项行、`**我的答案**`）正则切分，兼容全角冒号与全角字母。
- **落库规则**（`MistakeService::importUploadedItems()`）：`origin='upload'`，`item_key='upload-{sha256(考生id|题干|正确答案)前24}'` 幂等去重（重复上传只更新），错因口径与真题归集一致（服务端按所选/正确答案重算），options 按 `{label,text,mark}` 重构，单次最多 100 题，单条失败不影响整批。
- **接口**：`POST /mistakes/students/{code}/ai-analysis` 与 `/ai-analysis-stream` 新增 `importToMistakes` 参数（默认关）。流式端点在分析开始前先发 `data: {"importing":true}` 占位与 `data: {"import":{imported,updated,skipped}}` 结果事件；非流式端点在响应体带 `import` 字段。无新迁移（item_key/origin/content_hash 列已存在）。
- **前端**：分析页上传区新增「同时把识别出的错题加入错题本」开关（默认开，仅登录用户生效），分析按钮下方显示导入结果（新导入/更新/跳过数）。

## V0.1-dev.24 - 站点 logo 接入 - 2026-09-30

### 变更

- **页面图标**：`nuxt.config.ts` head 声明 `favicon.ico`（16/32/48 多尺寸）、`guanlan-logo-32.png`、`apple-touch-icon.png`（180×180，iOS 书签）。
- **页头品牌位**：`SiteHeader` 品牌区的「观」字方块替换为定稿 logo（`/logo/guanlan-logo-64.png`，32px 显示），样式类 `.brand-mark` → `.brand-logo`。
- **静态资源入库**：`apps/web/public/` 新增 favicon.ico、apple-touch-icon.png 与 logo/ 四档尺寸（32/64/512/510×433 透明母版）。

## V0.1-dev.23 - 注册邮箱验证码（腾讯企业邮 SMTP 发信）- 2026-09-30

### 变更

- **注册流程增加邮箱验证**：注册时必须先获取 6 位验证码并填入，验证通过才创建账号。
- **发信通道**：`App\Support\Mailer`（PHP 原生 stream SMTP，无新增 composer 依赖），支持 465 SSL / 587 STARTTLS；配置走 `MAIL_*` 环境变量，`MAIL_ENABLED=false` 时自动降级为原注册流程（不要求验证码）。发件邮箱：腾讯企业邮 `guanlan@wencker.top`。
- **新表**（迁移 `2026_09_30_000007`）：`email_verifications`（email / purpose / code_hash / attempts / expires_at / used_at / ip）。
- **接口**：新增 `POST /auth/email/code`（公开）。限流：同邮箱 60s 一次、每小时 5 封、同 IP 每小时 60 封；验证码 10 分钟有效、5 次错误作废；哈希存储；已注册邮箱不发信但返回同样提示（防枚举）。
- **注册接口** `/auth/register`：`MAIL_ENABLED` 时必填 `code`。
- **前端**：注册页新增验证码输入 + 「获取验证码」按钮（60 秒倒计时、发送成功提示）。

## V0.1-dev.22 - 文章评论区（登录可见可评、楼层/回复/用户组、后台管理）- 2026-09-30

### 变更

- **评论区上线**（真题分析 / 时政热点 / 时政预测三类文章详情页）：
  - 自建评论组件（未引入第三方评论服务，直接对接站内账号与用户组体系）。
  - **游客不可见不可评**：列表/发表接口均需登录（401），前端游客只显示「登录后可查看和发表评论」占位。
  - 楼层号（主楼自增，回复为一级楼中楼不占楼层）、置顶楼排最前、评论人昵称 + UserGroupBadge 用户组徽章。
  - 回复（@对方昵称）、删除自己的评论；加载更多分页（每页 20）。
  - 评论上限 1000 字。
- **数据层**（迁移 `2026_09_30_000006`）：
  - 新表 `comments`：user_id / article_type / article_slug / parent_id / floor / content / status(approved|pending) / pinned。
  - 三张文章表各加 `comment_mode`（open 自动发布 / review 审核后发布 / closed 禁止评论），默认 open。
- **接口**：
  - GET/POST/DELETE `/api/v1/comments`（登录）。
  - 后台：GET `/admin/comments`（按状态/栏目筛选）、PATCH `/admin/comments/{id}`（置顶/取消置顶/审核通过）、DELETE（删除含楼中楼）、PUT `/admin/comments/mode`（文章评论模式）；管理操作均写审计日志。
- **后台**：
  - 新页「评论管理」：全部/待审核/已通过 Tab + 栏目筛选，通过 / 置顶 / 删除。
  - 三个文章管理页每篇加「评论设置」下拉（自动发布 / 审核后发布 / 禁止评论）。

## V0.1-dev.21 - 真题排版修复 + 错题自动归集 + 错题收藏/删除 - 2026-09-30

### 变更

- **真题排版修复**：
  - 数据清洗（build_questions.py）：题干/选项中混入的【答案】X、【解析】尾巴剥离并入解析字段（286 处选项答案、221 处解析、5 处分析题参考答案块），
    空缺答案字段从【答案】标记回填；questions.json 重新生成并重建 manifest（1479 题，0 残留）。
  - 题干/选项/解析渲染启用 `white-space: pre-line`，OCR 换行与①②③④结构正常折行。
  - 非中文/含中文 pid（如 1994·文科）路由 404 修复：Hyperf 路由参数不解码，PaperController::show 与后台 paperQuestions 手动 rawurldecode。
- **真题错题自动归集**：
  - 新接口 POST /api/v1/study/attempts/batch（需登录）：真题整卷交卷时服务端判分，逐题落 attempts，
    错题（含未作答的客观题）自动 upsert 进本人错题本（mistake_items，origin='paper'，item_key='paper-q{题目id}' 去重，重复交卷只更新最新作答）。
  - 错因口径与数据集导入一致（未记录/既漏又错/纯错选/纯漏选），options 带 chosen/missed/hit 标记，错题册直接渲染。
  - 真题页交卷判分后自动上报（游客跳过），判分横幅提示「N 道错题已自动加入你的错题本」。
- **错题收藏 / 删除**：
  - 新接口 DELETE /api/v1/mistakes/items/{id}（本人或管理员）。
  - 错题册页每题新增「收藏」（复用 /study/favorites，targetType=mistake_item，刷新后状态保持）与「删除」（确认后移除）按钮。

## V0.1-dev.20 - 用户组体系 + 学习时长排行 + 时政 AI 供应商 + 后台体验 - 2026-09-29

### 变更

- **用户组体系**：users 表新增 `user_group`（迁移 `2026_09_30_000003`，未注册游客为隐式 guest），
  组别：普通用户 / VIP / SVIP / SSSVIP，管理员由 role 判定。
  - 新增 `UserGroup` 支持类：组别常量 + 权益映射（当前含每日 AI 分析报告次数：5/20/50/200，管理员不限），
    错题 AI 报告保存时按组校验每日次数（429 提示升级）。
  - 新增 `UserGroupBadge` 全站徽章组件：灰/蓝/紫/金/红金渐变/红 六档配色。
  - 用户组由后台手动指定：用户管理页下拉修改（PATCH /admin/users/{id} 的 userGroup 字段），落审计。
  - /auth/me 与后台用户列表均返回 userGroup + features。
- **学习时长统计**：
  - users 浏览时前端每 30 秒心跳上报（页面不可见暂停），POST /api/v1/stats/heartbeat（需登录）。
  - 新表 `user_study_stats`（迁移 `2026_09_30_000004`）按用户按天累计秒数。
  - 公开排行榜 GET /api/v1/stats/leaderboard?period=week|total（仅返回昵称 + 组徽章 + 时长）。
  - 首页侧栏新增「学习排行」卡片；后台总览新增同款卡片。
- **时政 AI 供应商**：支持 DeepSeek（官方预设 api.deepseek.com / deepseek-chat）、OpenAI 兼容、Claude、自定义；
  切换供应商自动带出预设地址/模型；DeepSeek 走 OpenAI 兼容通道；测试连接按当前配置实测。
- **后台体验**：后台导航新增「返回站点」按钮；总览页改双列网格布局（趋势/分布/排行/审计一屏尽览）。

## V0.1-dev.19 - 后台管理系统完善 - 2026-09-29

### 变更

- **用户管理**：后台新增创建用户（邮箱/密码/昵称/角色）与重置密码；操作入审计。
- **审计日志**：新增 `audit_logs` 查询接口（分页 + 按动作前缀/管理员过滤）与 `/admin/audit-logs` 页面；
  用户、时政热点、真题分析、试卷、题目、时政后台操作全部落审计。
- **试卷管理**：后台支持新增/编辑/删除试卷（有题目时需 force 确认）与编辑题目
  （题干/材料/选项/答案/解析/考点/模块/分值），试卷列表改用数据库 id 操作。
- **时政预测管理**：predictions 表新增 `status` 字段（迁移 `2026_09_29_000005`），
  后台可切换发布/隐藏，前台隐藏已下线内容。
- **总览增强**：近 14 天刷题趋势、内容状态分布（时政/分析/预测）、最近审计动态。

### 说明

- `apikeys` 等敏感配置仍只在服务端，接口回传掩码。

## V0.1-dev.18 - 内容同步：2026 年 9 月下旬时政 + 真题分析更新 - 2026-09-29

### 变更

- **时政热点**：新增「时政考点_2026年9月下_0927更新」，hotspots 共 4 期。
- **每日增补**（predictions，新增「每日增补」层）：
  - 时政增补_中美八点成果共识_20260927
  - 时政增补_生态环境法典_20260928
  - 时政增补_20260928-29（当日头条）
  - 时政增补_世界人工智能大会讲话_马原大题_20260929
- **真题分析更新**（analysis_articles 内容刷新）：选择题分析、分析题分析、综合结论。
- **ingest 工具**：`build_articles.py` 的时政增补从固定清单改为 `时政增补_*.md` 通配，
  以后往 raw 目录加增补文件即可被自动收录（layer=每日增补）。

### 修复

- dataset 生成后统一 LF 换行再计算 dataset-manifest 哈希，
  避免 Windows CRLF 与 `.gitattributes` 的 `storage/** text eol=lf` 规范化产生校验漂移（V0.1-dev.5 同款问题）。

### 说明

- 真题库 `questions.csv` 有变动，但 ingest 实际读取的 `questions.jsonl` / `papers/_index.json` 未变，题库数据无变化。

## V0.1-dev.17 - 每日时政：爬虫接入 + 后台 AI 筛选发布 - 2026-09-29

### 变更

- **爬虫入库**：人民日报/人民网每日抓取项目落到 `services/shizheng-crawler/`
  （自包含：抓取 `src/`、对接 `bridge/`、每日入口 `pipeline.py`、部署 `deploy/`）。
  cron 03:30 抓取前一天版面 → 提炼考研政治考点 → 推送候选到网站 API → 服务端 AI 筛选 → 自动发布到 hotspots。
  严格遵守 robots.txt `Crawl-delay: 120`，串行抓取。
- **新增数据表**（迁移 `2026_09_30_000001/000002`）：
  - `admin_settings`：后台键值配置存储（AI key 只存服务端）。
  - `shizheng_candidates`：每日时政候选池，按 (publish_date, title) 唯一，status 流转 pending → selected → published。
- **新增后台 API**（`/api/v1/admin/shizheng/*`，管理员权限）：
  配置读写（GET/PUT config，apiKey 掩码回传、留空不覆盖）、连接测试（config/test）、
  候选推送（POST candidates，幂等 upsert，单次 ≤200 条）、候选查询（GET candidates）、
  AI 筛选（POST screen，支持 auto=true 筛完即发）、手动发布（POST publish）。
- **新增后台页面** `/admin/shizheng`：AI 配置（provider/baseUrl/model/key）、测试连接、
  按日期查看候选、AI 筛选 / 筛选并发布 / 发布选中项；后台导航加「每日时政」入口。
- **筛选与发布逻辑**（`ShizhengScreeningService`）：
  AI 不可用或调用失败自动降级为规则筛选（按爬虫原始优先级），链路不断；
  发布写入 hotspots 时按标题去重，不覆盖后台人工编辑过的条目；
  重复筛选先复位再标记，保证幂等；baseUrl 校验拒绝内网/保留地址（防 SSRF）。

### 安全

- AI apiKey 永不出服务端：列表/配置接口只回掩码（`xxxx****xxxx`），保存时传空或掩码视为不修改。
- baseUrl 出网校验拒绝 localhost/.local/.internal 及私网、保留 IP 段。

### 部署

- `php bin/hyperf.php migrate` 创建两张新表。
- 爬虫服务端部署见 `services/shizheng-crawler/README.md`（/opt/shizheng + crontab）。

## V0.1-dev.16 - AI 分析流式输出（SSE） - 2026-09-29

### 变更

- **后端流式端点** `POST /api/v1/mistakes/students/{code}/ai-analysis-stream`：
  校验逻辑与非流式端点一致，通过后以 `text/event-stream` 逐段推送增量文本。
  服务端以 Guzzle `stream` 透传上游 SSE（openai/deepseek、claude 走标准流式协议；
  custom 端点若为 /chat/completions 兼容则同样流式，否则降级为一次性生成后整段推送），
  响应带 `X-Accel-Buffering: no` 防 nginx 缓冲。事件格式：
  `data: {"delta":"..."}` / `data: {"error":"..."}` / `data: {"done":true,"content":...,"usage":...}`。
  流中途异常但已有部分内容时，把已生成部分作为 `partial` 结果返回，不全量丢弃。
- **前端渐进渲染**：分析页改用 fetch + ReadableStream 消费 SSE，
  每收到 delta 实时追加并即时渲染 Markdown；`done` 事件以服务端全量内容兜底校准。
  流式失败（网关/浏览器不支持等）且尚未收到任何内容时，自动回退原非流式接口；
  已收到部分内容时保留内容并提示「分析中断」。
- 原 `ai-analysis` 非流式接口保持不变，作为回退通道。

### 体验

- 30s-1min 的干等（「分析中...」原地转圈）变为边生成边显示，首字可见时间 ≈ 模型首 token 时间。

### 验证

- `npm run build` 通过；部署后走真实分析验证流式渲染与自动保存链路。

## V0.1-dev.15 - 报告删除 + 错题册报告折叠 + 补更新记录 - 2026-09-29

### 变更

- **AI 分析报告删除**：新增 `DELETE /api/v1/mistakes/analysis-reports/{id}`（登录必需，
  仅能删除本人报告）；分析页历史报告列表与错题册页报告切换器均加删除按钮，
  删除当前展示的报告后自动切换到最新一份。
- **错题册页报告区块默认折叠**：报告全文改用 `<details>` 折叠（点开才展开），
  不再把错题列表挤出首屏；多份报告的切换器移入折叠区内。
- 补 V0.1-dev.12 ~ dev.14 的更新记录。

### 验证

- `npm run build`（apps/web）通过；部署后 `/api/v1/health` 返回 V0.1-dev.15。

## V0.1-dev.14 - 错题册页自动展示最新报告 + 历史切换 - 2026-09-29

### 变更

- **错题册页新增「AI 分析报告」区块**（`/mistakes/{code}`）：登录后自动拉取报告列表
  并渲染最新一份（marked 渲染），报告间可切换；此前报告只能在分析页查看，错题册页无任何入口。
- **分析页进入即展示最新报告**：已有历史报告且本次未跑新分析时，自动载入最新一份，
  免去重新上传文件的步骤。

## V0.1-dev.13 - 修复报告保存 503 - 2026-09-29

### 变更

- `MistakeAnalysisReport::$timestamps` 补 `bool` 类型声明，与父类
  `Hyperf\Database\Model\Model` 的 `public bool $timestamps` 对齐；此前任何触达该模型的
  请求都会触发继承类型冲突 Fatal，worker 异常退出返回 503，前端表现为「保存失败」。

### 教训

- 新增模型属性覆盖时必须带类型声明（参照 `MockQuestion.php` 的写法）；
  「看起来跑通的代码」要在真实登录链路上验证过才算数。

## V0.1-dev.12 - 错题 AI 分析白屏修复 + 报告落库 - 2026-09-29

### 变更

- **白屏根因修复**：分析结果渲染此前使用不存在的 `$md.render`（项目无 Nuxt 内置
  markdown 渲染器），首次成功拿到分析结果时 Vue 应用崩溃白屏；改用 `marked` 渲染，
  解析失败时降级为转义后的 `<pre>` 原文。
- **分析报告落库**：新增 `mistake_analysis_reports` 表（迁移
  `2026_09_29_000004`）与三个接口 —— `POST/GET /mistakes/students/{code}/analysis-reports`
  （保存 / 本人报告列表，每考生滚动保留最近 20 份）、
  `GET /mistakes/analysis-reports/{id}`（详情）；分析成功后自动保存。
- **nginx 分析超时放宽**：`docker/nginx/production.conf` 增加
  `proxy_read_timeout 300s`，避免 30s-1min 的分析请求被网关默认 60s 掐断。

### 部署注意

- `production.conf` 是单文件 bind mount，改 nginx 配置后必须
  `docker compose ... up -d --force-recreate gateway`，仅 `--build` 不会生效。

## V0.1-dev.11 - DeepSeek 官方 API 支持 + 网页地址误配识别 - 2026-09-29

### 变更

- **DeepSeek 官方 API 一等支持**：新增 `deepseek` 提供商（DeepSeek API 与 OpenAI
  完全兼容，归一化后复用 openai 通道），默认 `https://api.deepseek.com` +
  `deepseek-chat`，Base URL / 模型留空即用默认值；连接测试、模型获取、真实对话
  测试、分析全链路可用；前端提供商下拉新增 DeepSeek 并设为默认项，
  `getAIConfig` 同步补充。
- **网页地址误配识别**：用户把网站首页当 API 端点填写时（响应为 HTML），错误提示
  从「原始响应: <!doctype html>…」升级为明确指引：「端点返回的是网页而非 API
  响应——请检查地址是否为完整 API 端点（例如
  https://api.deepseek.com/chat/completions）」。覆盖分析空内容守卫（3 处）与
  真实对话测试的失败路径。
- 分析请求超时 60s → 120s（长文分析在中转站上更稳）。

### 验证

- `python tools/phpcheck.py`：OK；`npm test --prefix apps/web`：43/43。
- AIAnalysisTest 新增 deepseek 校验用例；生产部署后 6/6 全 PASS（见 V0.1-dev.9
  部署记录的验证通道）。

## V0.1-dev.10 - AI 分析走后端代理 + 连接测试/模型选择 - 2026-09-29

修复浏览器直连 AI 提供商导致的 `Failed to fetch`（网络不可达 + CORS），并补齐连接测试与模型选择。

### 变更

- **AI 调用全部改为后端代理**：前端 `analyze/[code].vue` 删除浏览器直连
  OpenAI/Claude/自定义 API 的三段 fetch 逻辑，统一走
  `POST /mistakes/students/{code}/ai-analysis`；该接口新增可选 `markdown` 参数
  （≤20 万字符），上传错题 Markdown 的场景由服务端 `analyzeMarkdown()` 包装提示词
  后转发。服务器出网环境稳定，彻底规避浏览器侧网络与 CORS 问题。
- **连接测试 + 模型获取**：新增 `POST /api/v1/mistakes/ai/test`（登录必需，
  RequireAuthMiddleware）：
  - openai / claude：GET 官方 `/models` 端点，返回 `ok`、`latencyMs`、排序后的
    `models` 列表；401/403 给出「认证失败」明确提示；
  - custom：仅探测端点可达性（2xx–4xx 视为可达，5xx 不可用），不支持自动获取模型；
  - 全路径复用 `assertAllowedUrl` SSRF 拦截，内网/保留地址直接拒绝。
- **前端配置面板**：新增「测试连接 / 获取模型」按钮与结果行（耗时、模型数、
  失败原因）；模型输入框挂 `datalist`，测试成功后自动填充候选模型。
- **登录要求**：分析页未登录时提示先登录（分析请求带鉴权由服务器转发）。

### 验证

- `python tools/phpcheck.py`：OK（124 files / 81 classes）。
- `npm test --prefix apps/web`：43/43 通过。
- 冒烟新增用例：`/mistakes/ai/test` 未登录 401；内网 baseUrl 返回 ok=false 且提示
  内网拦截。生产容器实测见下节部署记录。

## V0.1-dev.9 生产部署与容器验证 - 2026-09-29

服务器 root-189（/www/wwwroot/guanlan，宝塔 + Docker Compose）实际执行结果。

### 部署内容

- `git reset --hard` 对齐重写后的 `origin/v0.1-dev.7`（历史清理 + dev.8/9 全量内容）。
- **`composer require hyperf/guzzle`**（composer:2 容器内执行）：安装 hyperf/guzzle
  v3.1.66 + guzzlehttp/guzzle 7.15.5，AI 分析后端启用。提交 10c3752。
- 重建 api/web 镜像并重启；api 启动链自动跑 `verify-dataset → migrate --force →
  db:seed --force`。三个新迁移落库确认：`admin_audit_logs` 表、
  `mistake_reviews.consecutive_correct` 列、`hotspots/analysis_articles.status` 列。
- `/api/v1/health` 返回 `{"status":"ok","db":"ok","version":"V0.1-dev.9"}`。

### 部署过程中发现并修复的问题

1. **数据集 manifest 漂移（05a29e1）**：dev.6/7 提交 038ae9a 更新了 `mistakes.json`
   但未重生成 manifest（253087 字节实际 vs 253077 声明），容器 `verify-dataset`
   启动失败、api 崩溃循环。用 `tools/ingest/dataset_manifest.py` 的 build_manifest
   正式重生成，8 个数据集 + totals 全部对账一致。此前生产是靠服务器上未提交的手工
   manifest 修改在撑——重置工作区后暴露。
2. **管理员账号失效**：`admin@guanlan.local` 在 9/28 被普通注册抢占，MistakeSeeder
   的 seedAdmin 见邮箱已存在即跳过，导致系统无管理员、全部后台接口 403。已修数据
   （升级 role=admin）并修 seeder：邮箱被占时升级为管理员而非跳过。
3. **后台热点/分析编辑失效（遗留 bug）**：`ArticleResource::listItem` 从不输出
   `id`，而后台页的改状态/删除按钮依赖 `item.id`——该功能自上线起即不可用。资源
   已补 `id` 字段。
4. **/health 版本号硬编码**：改为读仓库根 VERSION 文件（镜像同步 COPY）。
5. **测试可运行性**：`AIAnalysisTest` 原 PHPUnit 风格但项目从未引入 phpunit，从来
   跑不起来——重写为项目统一的纯脚本风格（桩件、无外部依赖），并补 8 组 SSRF 拦截
   用例；`DatasetManifestVerifierTest` 原 shell 出调 python3 构建 fixture，api 容器
   无 python——改为纯 PHP 复刻 builder 算法。

### 容器验证记录（实际输出）

- `php -l` 全量：**lint checked=122 failed=0**（api 容器内执行）。
- PHP 测试 6/6：AIAnalysisTest / AccountIdTest / AdminCredentialsTest /
  ContentStatusTest / DatasetManifestVerifierTest / MistakeAccessTest 全部 PASS。
- `bash tools/smoke.sh`（生产网关 127.0.0.1:8080）：49 个用例 200/201；所有 4xx 均为
  预期（不存在资源 404、未登录 401、校验 422、重复注册 409）。第 10 节后台全矩阵
  通过：admin 全接口 200 / 无 token 401 / 非管理员 403；隐藏热点详情 404 → 恢复发布
  200；非法 status 回退 published；B1 profile 上传回读一致；B3 PATCH 200/403/404/422；
  B2 review-stats 200。
- 前端 43/43 node 测试通过（本机）。

## V0.1-dev.9 - 错题后台闭环 B1 - 2026-09-29

对应 [admin-update-plan](docs/superpowers/plans/2026-09-29-admin-update-plan.md) 阶段 B1 + 审计表前置。
分支 `feature/admin-mistake-console`（叠于 dev.8 之上）。

### 变更

- **审计表前置**：迁移 `2026_09_29_000003` 建 `admin_audit_logs`
  （admin_id / action / target_type / target_id / detail / created_at，双索引），
  新增 `AdminAuditService::log()`。阶段 B/C 的后台写操作统一走它，本期接入 profile 上传。
- **接通错题分析断头链路**：`MistakeController::detail` 原先读
  `students.detail_html`（注册生成的考生恒为空串），`mistake_profiles` 只写不读。
  现改为优先返回 profile 内容（`isDefault=false` 时），回退 `detail_html`；
  响应新增 `isDefault` / `updatedAt`。考生端错题本页新增「错题分析」折叠卡片，
  默认占位内容不渲染——管理员上传后考生端立即可见（验收标准①）。
- **后台错题接口**（全部在 `RequireAdminMiddleware` 组内，非管理员 403 = 验收标准②）：
  - `GET /admin/mistakes/students`：全量考生（含数据集考生 A），带错题数、模块/错因分布、
    绑定账号邮箱、来源标记；
  - `GET /admin/mistakes/students/{code}/items`：条目分页浏览（perPage ≤ 100）；
  - `GET /admin/mistakes/students/{code}/profile`：当前 Markdown 与来源信息；
  - `PUT /admin/mistakes/students/{code}/profile`：落 `MistakeProfileService::replace()`，
    记录 `source_file` 与 `updated_by`（验收标准③），并写审计日志
    `mistake.profile.replace`。
- **前端**：新增 `admin/mistakes.vue`（考生列表 → 条目分页浏览 → Markdown 编辑器：
  .md 文件上传或直接粘贴、来源文件名、保存发布），后台导航加「错题」入口。
- **B3 错题条目维护**：`PATCH /admin/mistakes/items/{id}`，管理员可改全局
  `action` / `errorType` / `module`（空请求 422、至少提供一个字段），写审计
  `mistake.item.update`（记录实际写入字段）。这是管理员改全局字段的正式通道，
  替代 dev.7 修复中「普通用户降级写全局 action」的旧路径遗留场景。
- **B2 复习数据看板**：`GET /admin/mistakes/review-stats`，按考生汇总错题数、
  学习者数、复习次数、正确率、new/reviewing/mastered/snoozed 分布与逾期数；
  单条 SQL（students 左联 items 左联 reviews，一次 group by），不逐用户循环。
  前端在错题后台页内嵌看板表格，逾期数红色高亮。
- **条目编辑 UI**：条目表新增「编辑」入口，行内表单修改行动建议/错因/模块，
  保存后本地同步更新。

### 验证

本机（Windows，无 PHP/Docker）实际执行：

- `python tools/phpcheck.py`：checked=122 files, classes=80, tables=25，OK。
- `npm test --prefix apps/web`：43/43 通过。
- `python tools/doclink.py`：断链仍为改动前已存在的 README→docs/api.md，无新增。
- `bash -n tools/smoke.sh`：语法通过；新增 B1 用例（后台考生列表/条目/profile GET、
  PUT 上传后回读 sourceFile 一致、非管理员 403 × 2、考生端 detail 200 且带内容），
  待容器内执行。
- 审计落库断言依赖真实数据库，待容器冒烟覆盖。
- B3 冒烟补例：PATCH 200 且回读一致、非管理员 403、不存在 404、空字段 422；
  B2 冒烟补例：review-stats 200。待容器内执行。

## V0.1-dev.8 - 后台内容管理补齐 - 2026-09-29

对应 development-plan 1.6，规划见 [superpowers/plans/2026-09-29-admin-update-plan.md](docs/superpowers/plans/2026-09-29-admin-update-plan.md) 阶段 A。

### 变更

- **内容状态字段**：迁移 `2026_09_29_000002` 给 `hotspots` / `analysis_articles` 加
  `status`（`published`/`hidden`，默认 published 并回填存量行）。新增
  `App\Support\ContentStatus` 统一口径：后台写入非法值回退 published，前台仅
  `hidden` 不可见。
- **前台出口全量过滤**：热点与分析的列表、详情（404）、首页热点卡片、全站搜索
  （`SearchService::articles`）对 hidden 内容统一隐藏；后台列表仍可见可改。
  注意：部署时必须先跑迁移再启动 API，否则旧行 NULL 会被 `<> hidden` 条件排除。
- **后台 CRUD 接入状态**：`saveHotspot` / `saveAnalysis` 接受并归一化 `status`；
  `ArticleResource` 输出 `status` 字段；热点/分析后台页新增「已发布/已隐藏」切换按钮。
- **后台只读查看**：新增 `GET /admin/papers`（分页）、`GET /admin/papers/{pid}/questions`
  （分页）、`GET /admin/mocks`、`GET /admin/predictions`，全部在 `RequireAdminMiddleware`
  组内，复用现有 Resource；前端新增 `admin/papers.vue`（含题目展开）、`admin/mocks.vue`、
  `admin/predictions.vue`，导航增加真题/押题/预测三项。数据集内容明确只读。
- **总览增强**：`overview` 增加 `registrationTrend`（近 14 天按天注册数）与
  `mistake_reviews` 计数；前端总览页渲染趋势柱状图；新增 `utils/trend.mjs`
  （`lastNDays` / `fillTrend` / `trendTotal` 纯函数）。
- **冒烟补例**：`tools/smoke.sh` 第 10 节增加 4 个后台只读接口、无 token 401、
  隐藏热点详情 404 → 恢复发布 200、非法 status 回退 published 的用例。

### 验证

本机（Windows，无 PHP/Docker）实际执行：

- `python tools/phpcheck.py`：checked=120 files, classes=79, tables=23，OK。
- `npm test --prefix apps/web`：43/43 通过（新增 trend 纯函数 5 组用例）。
- `python tools/doclink.py`：断链 7 处，均为改动前已存在（HEAD 上输出一致），无新增。
- `bash -n tools/smoke.sh`：语法通过。
- 新增 `apps/api/tests/ContentStatusTest.php`（normalize/isVisible 共 12 组断言），
  已接入项目测试目录；因本机无 PHP 未实际执行，待容器 `tools/lint.sh` 验证。
- `bash tools/smoke.sh` 与迁移 `2026_09_29_000002` 待容器内执行。

## 错题板块修复 - 2026-09-29

### 变更

- **AI 服务懒加载（P0）**：`MistakeController` 不再在构造函数注入 `AIAnalysisService`
  （其依赖 `Hyperf\Guzzle\ClientFactory`，而 `hyperf/guzzle` 未入库，曾导致错题模块
  全量 500）。改为请求时经容器懒加载，包缺失时返回 503 提示，错题核心功能不受影响。
  启用 AI 分析需在生产执行 `composer require hyperf/guzzle`。
- **行动建议写权限拆分**：全局 `action` 仅管理员可改；普通用户的行动建议写入
  `mistake_reviews.personal_action`（列此前已存在但未使用）。错题列表接口按登录态
  合并返回 `personalAction`，前端展示优先级：本次会话 > 个人建议 > 全局建议。
- **复习统计去重**：`MistakeReview::isDueToday()` 改为仅统计「今天之内」到期，
  逾期的归入 `overdue`，修复前端 `dueToday + overdue` 双重计数。
- **真实连胜算法**：`mistake_reviews` 新增 `consecutive_correct` 列（迁移
  `2026_09_29_000001`），答错清零、答对 +1，达到 3 判定已掌握。替换原先用累计
  `correct_count` 近似的错误逻辑（该逻辑下「对对错对」会直接跳到已掌握）。
- **`updateReviewStatus` 容错**：无复习记录时自动创建（原先 `firstOrFail` 抛 500）；
  `nextReviewAt` 先经 `strtotime` 校验，非法格式返回 422。
- **AI 出网校验（SSRF）**：`AIAnalysisService` 对用户输入的 `baseUrl` / `endpoint`
  校验协议与主机，拒绝内网、保留 IP 与 `.local` / `.internal` 域名。
- **旧账号编号兼容**：`MistakeAccess::normalizeCode()` 对纯数字编号去前导零再比较，
  旧格式 `mistake_code`（如 `2`）可命中新格式错题本编号（`000002`）；带空白的编号
  仍拒绝。`allowedCodes` 同时返回两种形式。
- **并发首访**：`ensureReviews` 改用 `insertOrIgnore`，依赖唯一索引幂等。
- **前端**：复习概览 `mastered/total` 在 total 为 0 时不再显示 NaN%；AI 分析页
  Claude 直连补 `anthropic-dangerous-direct-browser-access` 头修复 CORS。

### 验证

- `python tools/phpcheck.py`：checked=118 files, classes=78, tables=23，OK。
- `npm test --prefix apps/web`：38/38 通过。
- `MistakeAccessTest` 补充 5 个编号归一化用例（旧新格式互通、空白拒绝、前导零不串号）。
- 本机无 PHP/Docker：`php -l`、PHP 测试与 migration 实际执行待部署环境跑
  `tools/lint.sh` 与 `db:migrate`。

## 错题可见性、访客配额与登录态 SSR - 2026-09-27

### 变更

- **错题可见性收紧**：新增 `App\Support\MistakeAccess`，考生 A 为公开模板，
  其余编号仅「绑定账号」与管理员可见。覆盖 `students` / `show` / `items` /
  `handbooks` / `detail` / `handbooks/{id}` / `items/{id}/action` 七个入口，
  其中 `handbooks/{id}` 反查手册归属，避免绕过 code 直取私有手册。
- **全站搜索防泄露**：`/search` 的错题结果按可见编号白名单过滤。
- **账号 ID**：`users` 新增 `mistake_code`（可空、唯一）。注册时取最小未占用的
  纯数字编号（1、2、3…），一 ID 绑定一账号并对应同编号错题本；唯一索引兜底并发，
  冲突最多重试 5 次。个人中心展示该 ID，后台用户列表可查看与改绑（撞号返回 422）。
- **访客配额**：新增 `App\Support\GuestQuota`，真题分析 / 时政热点 / 时政预测
  各自可免费阅读 3 篇。列表仍返回全部条目并带 `locked`，超额详情返回 403。
  配额按「栏目整体顺序」计算，与列表筛选条件无关，保证列表与详情边界一致。
- **登录引导**：新增 `LoginGateModal.vue`。锁定卡片点击即弹窗；详情被 403 拦下会
  跳回对应列表并带 `?login=1` 自动弹窗，「去登录」携带 `redirect` 回跳原页。
- **登录态迁移 Cookie**：token 从 localStorage 迁到 Cookie（30 天，`SameSite=Lax`，
  HTTPS 加 `Secure`），SSR 首帧即可解析身份，消除已登录用户的游客态闪烁；
  `useApiFetch` 透传 Authorization，缓存 key 含 URL 与 token。
- **错题列表公告**：`/mistakes` 顶部提示错题分析服务消耗 token、暂不支持免费分析。
- **移除考生 B**：`mistakes.json`、`dataset-manifest.json`、
  `tools/ingest/build_mistakes.py` 三处同步移除，避免重新生成时回归。
- **修复**：`/mistakes/{code}.vue` 的「看提分手册」链接指向不存在的
  `/mistakes/{code}/handbook/{id}`，已改为实际路由 `/mistakes/{code}/{id}`。
- **修复**：列表页原先在前端按优先级重排序，会让服务端算好的 `locked` 分界错位；
  现改为顺序完全由服务端决定。

### 验证

本机（Windows，无 Docker；WSL 无 PHP）实际执行：

- `php -l`（`src/config/migrations/seeders/tests/bin` 共 110 个文件）：0 失败。
- `php tests/MistakeAccessTest.php`：`MistakeAccessTest: PASS`；
  把 `GuestQuota::FREE_PER_SECTION` 改成 +10 后该测试 FAIL，确认能抓到配额破坏。
- `php tests/AccountIdTest.php`：`AccountIdTest: PASS`；
  把 `isDuplicateMistakeCode` 改名后该测试 FAIL，确认能抓到重试逻辑缺失。
- `php bin/verify-dataset.php`：`Dataset integrity OK: 8 files`；
  另用临时副本篡改 `mistakes.json.items` 后返回
  `mistakes.json.items mismatch`，负向用例有效。
- `python tools/phpcheck.py`：`checked=104 files, classes=70, tables=20`，`OK`。
- `python -m unittest discover -s tools/ingest -p "test_*.py"`：20/20 通过。
- `npm test --prefix apps/web`：38/38 通过（新增 `splitByLock` 两组用例）。
- `npm run build --prefix apps/web`：Nuxt 生产构建成功，`Σ Total size: 5.19 MB (1.35 MB gzip)`。
- SSR 冒烟：`node .output/server/index.mjs` 启动后
  `/ /mistakes /analysis /hotspots /predictions /login` 全部 200，stderr 无报错。

### 已知限制

- 本机无 Docker、WSL 无 PHP，以下未执行也未声称通过：`docker compose up --build`、
  `tools/lint.sh`（容器内 PHP lint + 三个测试）、`tools/smoke.sh` 权限与配额用例、
  migration 与 Seeder 的真实数据库写入。相关条目保持「进行中」。
- 数据库里已存在的考生 B 数据需重新执行 Seeder（容器重建流程已含 `db:seed --force`）才会清除。
- `python tools/doclink.py` 报 `README.md: docs/api.md` 断链。该问题在本次改动前的
  `HEAD` 上即存在（`docs/api.md` 从未入库），与本次改动无关，未修。

## V0.1-dev.5 - 2026-09-27

### 变更

- 当前版本表面从 `V0.1-dev.4` 升级为 `V0.1-dev.5`，同步更新 `VERSION`、健康检查 API
  版本字段、前端抽屉版本行、README 当前版本行与开发规划当前版本行。历史版本标题未改动。
- 新增生产部署（宝塔 + Docker）配套脚本与文档后的集成验收记录。

### 验证

- `git diff --check`：通过（无空白错误）。
- `python tools/doclink.py`：`checked 23 relative links in 7 files`，`OK`。
- `python tools/phpcheck.py`：`checked=101 files, classes=68, tables=20`，`OK`。
- `python tools/phpcheck_selftest.py`：7 个案例全部 `PASS`，`SELFTEST OK`。
- `python -m unittest discover -s tools/ingest -p "test_*.py" -v`：20/20 通过。
- `npm test --prefix apps/web`：36/36 通过。
- `npm run build --prefix apps/web`：Nuxt 生产构建成功，`Σ Total size: 5.17 MB (1.34 MB gzip)`。

### 已知限制

- 当前主机没有 PHP，未执行 PHP lint 或可执行 PHP 测试。
- Docker/PHP/live-MySQL 相关步骤（`compose up`、`lint.sh`、`smoke.sh`、`deploy.sh`
  线上运行、`docker inspect`、`healthcheck.sh`）在本机不可运行，均已 SKIPPED，未声称成功。
- 生产部署（1.7）与数据导入闭环（1.4）保持 `进行中`：Docker/live 验证证据缺失，
  待目标服务器实测后再标记 `已完成`。

## 文档补充 - 2026-09-26

### 变更

- 记录数据集完整性工作流：工作区只读 `storage/raw/` 副本经 `build_all.py` 生成 8 个数据集与
  `storage/dataset-manifest.json`，API 在 migration/Seeder 前校验摘要与来源清单。
- 明确 F 盘资料只作为只读来源，需复制到被忽略的工作区存储，且不挂载到 Docker。
- 开发规划 1.4「数据导入闭环」保持 `进行中`，等待最终生产集成验证。

### 验证

- `python tools/doclink.py`：`checked 21 relative links in 6 files`，`OK`。
- `python tools/phpcheck.py`：`checked=100 files, classes=67, tables=20`，`OK`。
- `python tools/phpcheck_selftest.py`：7 个案例全部 `PASS`，`SELFTEST OK`。
- `python -m unittest discover -s tools/ingest -p "test_*.py" -v`：20/20 通过。
- `git diff --check`：通过。

### 已知限制

- 当前主机没有 PHP，未执行 PHP lint 或可执行 PHP 测试；按当前环境约束未启动 Docker，
  因此未记录容器负向/正向冒烟结果。

## V0.1-dev.4 - 2026-09-23

### 修复与新增

- 修复 `AuthController`、`StudyController` 中未加括号的 PHP `new Class()->method()` 链式调用语法错误，
  恢复登录、收藏、笔记、进度、统计和后台接口。
- 修复 Docker 权限：WSL2 Ubuntu-24.04 用户 `administrator` 加入 `docker` 组；普通用户可运行
  `docker version`（29.8.1）和 `docker ps`。
- 新增 `PATCH /api/v1/mistakes/items/{id}/action`，登录用户可保存错题重练后的行动建议。
- 错题页新增单选/多选重练、即时判分、原错因对比，并将作答写入 `/study/attempts`。
- `tools/phpcheck.py` 新增未加括号 `new Class()->method()` 风险检查，反向自测扩展为 6 类错误。

### 验证

- `python tools/phpcheck.py`：`checked=99 files, classes=66, tables=20`，`OK`。
- `python tools/phpcheck_selftest.py`：6 类错误用例与 clean tree 全部 `PASS`，`SELFTEST OK`。
- `npm test --prefix apps/web`：36/36 通过。
- `npm run build --prefix apps/web`：Nuxt 生产构建成功，`Σ Total size: 5.17 MB (1.34 MB gzip)`。
- WSL 原生验证副本执行 `docker compose up --build -d` 成功，19 个 migration 与 Seeder 成功；
  `bash tools/smoke.sh` 通过。认证、用户态、后台、搜索、分页和错题 action 接口均验证。

## V0.1-dev.3 - 2026-09-22

本版本把 `F:\2027考研资料\考研政治` 的时政热点、真题分析与个人错题分析
全部接入站点，并补齐站点基础功能：真题回顾、模拟押题、站内搜索、登录注册、
用户学习记录与后台管理。

### 新增

**离线导入器（`tools/ingest/`，只读源目录 -> `storage/dataset/*.json`）**

- `common.py`：Markdown->HTML（tables/fenced_code/sane_lists）、星级 `★`->`S/A/B/C`、
  文本清洗、稳定 slug（ascii 前缀 + sha1 后缀）。
- `build_questions.py`：`真题库v2` -> `papers.json`（42 卷）+ `questions.json`（1479 题）。
- `build_articles.py`：-> `analysis_articles.json`（13 篇）+ `hotspots.json`（3 期）
  + `predictions.json`（6 篇）。真题分析按 `ANALYSIS_CATEGORY` 分「选择题规律 /
  分析题规律 / 会议与周年 / 综合结论 / 数据说明」五类。
- `build_mistakes.py`：-> `mistakes.json`（2 名考生 / 292 题 / 5 份提分手册）。
  解析 `**上次**：我选 **B** ｜ 正确 **D** ｜ 单选` 状态行、`- A．选项 ← 标记`
  选项行、`错题明细.md` 的两种表格（马原式 / 毛中特式）以回填 `action` 与 `errorType`。
- `build_mocks.py`：-> `mocks.json`（1 套 / 38 题）+ `stats.json`（图表数据）。
- `build_all.py`：总入口，打印各数据集条数。

**后端（`apps/api`）**

- 14 个新迁移（迁移总数 19）：`papers`、`questions`、`analysis_articles`、`hotspots`（扩展）、
  `analysis_articles`（扩展 `release`/`priority`）、
  `predictions`、`mistake_students`、`mistake_items`、`mistake_handbooks`、
  `mocks`、`mock_questions`、`users`、`user_tokens`、`study_tables`
  （favorites / notes / attempts / study_progress）。
- 20 个模型、10 个 Service、13 个 Controller、15 个 Resource、3 个中间件。
- 52 个路由注册，统一 `{"data": ...}` 包络与 camelCase 字段：
  - 内容只读：`/papers`、`/papers/{pid}`、`/papers/modules`、`/questions`、
    `/questions/{id}`、`/analysis`、`/hotspots`、`/predictions`、`/mocks`、
    `/stats/overview`。
  - 认证：`/auth/register`、`/auth/login`、`/auth/me`、`/auth/logout`。
  - 用户态：`/study/favorites`、`/study/notes`、`/study/attempts`、
    `/study/progress`、`/study/stats`。
  - 后台：`/admin/overview`、`/admin/users`、`/admin/hotspots`、`/admin/analysis`。
- 令牌认证：`Authorization: Bearer <token>`，库里只存 `sha256(token)`，
  30 天有效期；未登录不拦截（`AuthMiddleware`），由 `RequireAuthMiddleware` /
  `RequireAdminMiddleware` 决定拒绝。
- 5 个 Seeder 读数据集幂等灌库，含管理员 `admin@guanlan.local`。
- 真题 `reveal=0` 时服务端不下发 `answer` 与 `analysis`。

**前端（`apps/web`）**

- 页面：`/papers`（列表 + 详情 + 逐题作答）、`/hotspots`、`/predictions`、
  `/analysis`（列表 + 阅读页）、`/mocks`（在线作答 + 交卷判分）、`/mistakes`
  （考生切换 + 模块/章节筛选 + 错题详情 + 提分手册）、`/search`、`/login`、`/me/*`
  （收藏 / 笔记 / 进度）、`/admin/*`（总览 / 用户 / 热点 / 分析）。
- `composables/useAuth.ts`（token 存 localStorage + `useState` 共享）、
  `composables/useApi.ts`（SSR 走容器 DNS、客户端走 nginx 反代）、
  `utils/quiz.mjs`（判分）、`utils/articles.mjs`（排序/分组/摘要）、
  `utils/search.mjs`（类型标签、结果计数、路由解析）。
- 站内搜索新增聚合端点 `GET /api/v1/search?q=&type=&limit=`：一次检索
  真题 / 试卷 / 分析 / 热点 / 预测 / 模拟 / 错题七类，返回 `items`（含
  `type/title/snippet/url/meta`）、`total` 与 `groups` 计数。
- 真题分析新增「发行版」标记：同一主题的工作稿与定稿都保留，定稿带
  `release=true`，列表排序与卡片徽标都优先展示。

### 修复

- 修复 `MockQuestion::$timestamps` 未声明类型导致的 Fatal error。
- 修复 `ArticleService::hotspots()/hotspotCard()` 查询不存在的 `sort_order` 列
  （`hotspots` 表实际列为 `period`/`priority`/`published_at`）。
- 修复 `AppExceptionHandler` 基类错误：应为
  `Hyperf\ExceptionHandler\ExceptionHandler`，不是
  `Hyperf\HttpServer\Exception\Handler\ExceptionHandler`；签名改为
  `handle(Throwable, $response)` + `setStatus()/setHeader()/setBody()`。
- 修复文章摘要提取：跳过表格行、表格分隔线、引用、标题与列表标记，
  原先摘要会以 `| 项目 | 说明 |` 或 `>` 开头。
- 修复真题分析 slug 冲突：`选择题绝对错误选项规律v2_发行版.md` 与工作稿标题
  相同，slug 相同会被唯一约束合并掉发布稿，现为发行版追加区分后缀。
- 修复 `utils/search.mjs` 的 `resolveHitUrl` 会接受站外链接
  （`https://` / 协议相对 `//`），现只接受站内相对路径。

### 变更

- 版本从 `V0.1-dev.2` 更新为 `V0.1-dev.3`，同步 `VERSION` 与 README。
- 新增 `hotspots` 扩展列（`slug`/`period`/`priority`/`published_at`/`html`/
  `outline`/`source_file`/`word_count`），长文与首页卡片共用一张表：
  卡片行 `slug` 为 NULL，长文行 `slug` 非空。
- `analysis_articles` 新增 `release`（是否定稿）与 `priority`（星级）两列，
  列表按 `release DESC, sort_order ASC` 排序。
- 新增 `tools/phpcheck.py`：离线 PHP 结构检查器，覆盖
  PSR-4 命名空间一致性、括号配平、`extends`/`new`/`::` 类引用可解析性、
  模型 `$casts` 列与迁移列一致、路由到控制器方法存在性。
- 新增 `tools/phpcheck_selftest.py`：反向注入 5 类真实错误，验证检查器本身有效。
- 新增 `tools/verify.ps1` / `tools/verify.sh`：本机离线验证总入口。
- `tools/smoke.sh` 扩展第 7-11 组：统一检索、认证、用户态、后台、详情页 404。

### 验证

离线验证（Windows 本机，无 PHP / 无 Docker）：

```
powershell -NoProfile -ExecutionPolicy Bypass -File tools/verify.ps1

== Python 语法检查（tools）
   OK
== PHP 结构检查（PSR-4 / 模型列 / 路由）
checked=99 files, classes=66, tables=20
   OK
== PHP 检查器反向自测（5 类错误必须被抓到）
PASS  psr4-mismatch          exit=1
PASS  unbalanced-brace       exit=1
PASS  bad-cast-column        exit=1
PASS  route-missing-method   exit=1
PASS  unresolved-class       exit=1
PASS  clean-tree             exit=0

SELFTEST OK
   OK
== 数据集重新生成
papers=42 questions=1479
analysis_articles.json 13
hotspots.json 3
predictions.json 6
students=2 items=292 handbooks=5
mocks 1 [38] [33]
   OK
== 前端测试（node --test）
# tests 32
# pass 32
# fail 0
   OK
全部离线验证通过。
```

- `npm run build`（`apps/web`）：通过，Nuxt 3.21.11 生产构建成功，
  `Σ Total size: 5.16 MB (1.34 MB gzip)`，新增 chunk `search-*.mjs`。
- 数据集规模：`papers.json` 42 卷、`questions.json` 1479 题、
  `analysis_articles.json` 13 篇、`hotspots.json` 3 期、
  `predictions.json` 6 篇、`mistakes.json` 2 考生 / 292 题 / 5 手册、
  `mocks.json` 1 套 38 题（33 题带答案）、`stats.json` 182 项。

### 已知限制

- **Docker 权限已修复**：WSL2 Ubuntu-24.04 用户 `administrator` 加入 `docker` 组并重新启动发行版；
  `docker version` 显示 29.8.1，普通用户可运行 `docker ps`。
- 从工程工作区复制到独立验证目录 `~/guanlan-verify`（排除密钥、缓存、依赖及构建产物）后，
  `docker compose up --build -d` 构建成功，API 日志确认 19 个 migration 与 Seeder 完成；
  数据统计为 analysis 13、hotspots 3、predictions 6、mistake students 2 / items 292 / handbooks 5、papers 42 / questions 1479。
- 容器启动期间 `/api/v1/health`、`/api/v1/search?q=马原`、
  `/api/v1/mistakes/students/A/items?page=2&perPage=20` 均返回 HTTP 200。
- **验证仍未完成**：WSL 发行版在调用结束后自动停止，Docker daemon 随之退出，导致 Windows `localhost:8080` 不可持续访问；
  `tools/smoke.sh` 未完整执行。开发规划 B1 保持进行中。
- 站内搜索用 SQL `LIKE` 全表扫，数据量继续增长需换索引或 Meilisearch。
- `documents` 表（V0.1-dev.2 建立）本期仍无数据，PDF 在线阅读未开始。
- 模拟押题仅 1 套；`船` 目录下的其他模拟卷未纳入。
- 考生 B 的马原批次源文件导出时删掉了错选项，15 条无法判定错因，
  记为 `errorType=未记录`。
- 移动端为响应式降级，未做专属布局。
- `apps/web` 的 peer 依赖冲突仍以 `.npmrc` 的 `legacy-peer-deps` 绕过。

## V0.1-dev.2 - 2026-09-19

### 新增

- 新增五张内容表迁移：`subjects`/`chapters`/`knowledge_points`/`hotspots`/`documents`，`knowledge_points` 与 `documents` 预留 `category`/`year`/`source` 字段。
- 新增 Hyperf 查询 API 四端点：`/api/v1/health`（含 DB 连通性）、`/api/v1/home`、`/api/v1/subjects`、`/api/v1/subjects/{slug}`，统一 `{"data": ...}` 包络、camelCase 字段。
- 新增 Model / Service / Resource / Controller 分层，Resource 精确控制对外契约（章节以 `chapter_key` 作对外 id、热点 `updatedAt` 取 `updated_at`）。
- 新增 Seeder 把前端静态 `data/*.ts` 内容灌入 MySQL，全部 `firstOrCreate` 幂等。
- 新增前端 API 层：`utils/api.mjs`（`unwrapEnvelope`/`toHomeSummary`）、`composables/useApi.ts`（SSR 走容器 DNS、客户端走 nginx 反代）、`types/api.ts`、`tests/api.test.mjs`（6 例）。
- 首页、学科总览页、学科详情页改用 SSR `useApiFetch` 取数，保留 `?chapter=` 高亮与空态；新增 `apps/api/config/autoload/commands.php` 注册数据库控制台命令；新增 `apps/api/.env.example`。

### 修复

- 修复 Hyperf 骨架缺失导致无法启动：补齐 `config/config.php`、`config/container.php`、`config/autoload/{server,databases,exceptions,logger,dependencies}.php` 与标准 `bin/hyperf.php` 入口，并修正 config 文件里 `env()` 未导入命名空间（须 `use function Hyperf\Support\env;`）。
- 修复 `migrate`/`db:seed` 命令未注册：本地 Composer 镜像的 `hyperf/database` dist 缺少 `ConfigProvider`，命令未并入 `config('commands')`；改由 `commands.php` 返回 `CommandCollector::getAllCommands()` 补齐。
- 修复 Seeder 类名解析失败：Hyperf 以全局命名空间按文件名解析 seeder（`new Str::studly(basename)()`），故移除 seeders 的 `App\Seeders` 命名空间，并删除会重复执行的 `DatabaseSeeder`，改由 `HotspotSeeder` 的「subjects 为空则先跑 SubjectSeeder」保证顺序。
- 修复热点双份硬编码：删除后端 `HealthController::home()` 硬编码与前端 `data/home.ts`、`data/subjects.ts`，改由数据库单一来源。
- 修复 `docker-compose.yml` 的 `api` 服务缺少 `DB_*` 账号密码导致连不上 mysql。
- 修复 `apps/web` 在容器内 `npm ci` 失败（commander/cac/@nuxt/schema 版本漂移使 lock 与 package.json 在 npm 10 下不同步）：用容器同版本 npm 重新生成 `package-lock.json`，并新增 `apps/web/.npmrc`（`legacy-peer-deps=true`）对齐 peer 解析。

### 变更

- 版本从 `V0.1-dev.1` 更新为 `V0.1-dev.2`，同步 `VERSION`、README 与 SiteHeader 抽屉文案。
- 根 `.env.example` 的 `MEILI_HOST` 统一改名为 `SEARCH_HOST`，与 compose 运行时对齐。
- `apps/api/Dockerfile` 增加 Aliyun Debian/Composer 镜像、`libbrotli-dev`/`libssl-dev` 依赖、`platform.php=8.3.33`，CMD 改为「等待 mysql → migrate → db:seed → start」链。
- README 增补「本机 Docker 工作流（WSL2，无 Docker Desktop）」。

### 验证

- 容器链路（WSL2 Ubuntu-24.04 原生副本 `~/guanlan`，`docker compose up --build -d`）：
  - `GET /api/v1/health` → `{"data":{"status":"ok","db":"ok","version":"V0.1-dev.2"}}`
  - `SHOW TABLES` → subjects / chapters / knowledge_points / hotspots / documents + migrations
  - 行数：subjects=6 / chapters=12 / knowledge_points=36 / hotspots=6 / documents=2
  - `GET /api/v1/home` → 热点等级序 S·S·S·A·A·A，`updatedAt` 2026-09-06…09-01，学科摘要 count 3/2/3/6/3/7
  - `GET /api/v1/subjects` → 200；`GET /api/v1/subjects/marxism` → 章节 `materialism`/`epistemology` 含 points；`GET /api/v1/subjects/not-exist` → 404
  - 首页 SSR HTML 命中「十五五规划与开局之年」「马克思主义基本原理」，证明服务端经 `http://api:9501` 取数而非客户端兜底
  - 重复 `php bin/hyperf.php db:seed --force` 后行数不变（6/12/36/6/2），退出码 0
- `npm test`（`apps/web`）：11/11 通过（home / subjects / api 三文件）。
- `npm run build`（`apps/web`）：通过，Nuxt 3.21.11 生产构建成功。
- `git diff --check`：通过（无空白错误）。

### 已知限制

- `documents` 的 `category`/`year`/`source`/`file_path`/`subject_id` 本期无数据，manifest→MySQL 导入器顺延到后续版本。
- Meilisearch 接入为后续版本；搜索、用户中心、收藏、阅读记录仍显示「即将开放」。
- 时政热点对外显示名以 `subjects.name`（当代世界经济与政治）为准，不再保留旧 `home.ts` 的「形势与政策」别名。
- `hotspots.title` 具 UNIQUE 约束，重名热点会被 `firstOrCreate` 合并。
- `apps/web` 的 peer 依赖冲突（`@unhead/vue` 需 `vue>=3.5.18`，root 固定 `vue@3.5.12`）以 `.npmrc` 的 `legacy-peer-deps` 绕过，未真正升级依赖，根治需单独任务。
- `apps/api/.env` 被根 `.gitignore` 忽略、不入库，运行期变量由 `docker-compose.yml` 注入；`.env.example` 仅用于脱离容器的本地开发。
- 本版本验证在 WSL2 原生副本内完成（宿主机 Windows 10 LTSC 19044 无法运行 Docker Desktop）。

## V0.1-dev.1 - 2026-09-07

### 新增

- 新增 `/subjects` 六大学科资料库总览页。
- 新增 `/subjects/:slug` 学科详情页，包含面包屑、返回入口、章节卡片和知识点列表。
- 新增共享桌面导航、移动端抽屉菜单和底部资料导航。
- 首页学科卡片和热点推荐改为真实路由跳转，并保留章节 query 参数。
- 新增前端静态学科数据模型和 slug 查询测试，为 V0.1-dev.2 API 接入预留字段。
- 将完整版本路线写入 `docs/release-roadmap.md`，并记录本版本设计与实施计划。

### 修复

- 修复首页“进入资料库”和学科卡片使用 `href="#"` 死链接的问题。

### 变更

- 版本从 `V0.1` 更新为 `V0.1-dev.1`。
- 本阶段明确限定为纯前端静态数据，不新增 Hyperf API 或 MySQL 逻辑。

### 验证

- `npm test`（`apps/web`）：5/5 通过。
- `npm run build`（`apps/web`）：通过，Nuxt 3.21.11 生产构建成功。
- `git diff --check`：通过。

### 已知限制

- 学科、章节和知识点仍为前端静态种子数据，尚未接入 Hyperf/MySQL。
- 搜索、用户中心、收藏和阅读记录仍显示为即将开放。
- 构建过程存在 Nuxt/Vue 依赖的 Node `DEP0155` trailing slash 弃用警告，不影响构建结果。

## V0.1 - 2026-09-07

### 新增

- Nuxt 3 响应式首页，包含观澜品牌、继续阅读、六大学科入口和最新时政热点推荐。
- Hyperf `/api/v1/health` 与 `/api/v1/home` API 骨架。
- 本地资料清单工具，按 SHA-256 去重并生成导入审计清单。
- Docker Compose 编排 Nuxt、Hyperf、MySQL、Redis、Meilisearch 与 Nginx 的基础结构。

### 修复

- 初始版本暂无已记录的错误修复。

### 变更

- 建立 `main` 稳定分支及 `feature/<topic>`、`fix/<topic>`、`docs/<topic>` 分支约定。
- 建立版本文件、提交前缀、同步清单和 CHANGELOG 固定格式。

### 验证

- `node --test apps/web/tests/home.test.mjs`：2/2 通过。
- `python -m unittest tools/ingest/test_manifest.py`：2/2 通过。
- `npm run build`（`apps/web`）：通过，Nuxt 3.21.11 构建成功。
- `npm audit --omit=dev --audit-level=high --json`：high/critical 漏洞为 0。
- 本地预览 HTTP 返回 200，页面包含观澜品牌和热点推荐内容。

### 已知限制

- Hyperf 业务数据库、用户认证和邀请码系统尚未实现。
- 真实 Meilisearch 搜索接入尚未实现。
- PDF 阅读器、OCR 管理流程尚未实现。
- 多页面导航和二级菜单尚未实现。
- 当前开发机未提供 Docker，Compose 仅完成编排骨架，尚未在本机启动完整服务。
