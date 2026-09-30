# 时政每日抓取 → 观澜网站

每天自动抓取《人民日报》全版面 + 人民网新增文章，提炼成考研政治考点推送到观澜网站；
**AI 筛选分析在网站服务端完成**（后台「每日时政」页可填 AI API、查看候选池、一键筛选/发布）。

**自包含、可移植**：整个目录可原样挪入观澜仓库（如 `guanlan/services/shizheng-crawler/`），
路径默认全部相对于本目录，配置全部走 `.env` / 环境变量。

## 流程

```
cron 03:30
  → src.main --mode daily        抓取（限速按主机读 robots.txt，串行，约 2.5~3 小时）
  → pipeline.py                  提炼当天 → 全部候选 POST /admin/shizheng/candidates
  → POST /admin/shizheng/screen  服务端 AI 筛选（auto 自动发布到 hotspots）
  → 网站 /hotspots 页面展示；后台 /admin/shizheng 可人工复筛/发布
```

网站端功能在 `guanlan-patch/`（新增文件 + 两处插入片段，应用步骤见其 README）。
补丁未部署时 pipeline 自动回退：本地选 top N 直推 hotspots（也可 `--legacy` 强制）。

幂等：抓取按 URL sha256 去重；候选按 (日期,标题) upsert；发布按 hotspots 标题去重（不覆盖后台人工修改）。
告警：当天 0 篇 / 候选 0 条 / 推送或筛选失败 → ALERT_WEBHOOK 通知。

## 本地跑通

```bash
python -m venv venv
venv\Scripts\activate          # Windows；Linux: source venv/bin/activate
pip install -r requirements.txt
cp .env.example .env           # 填 LLM_API_KEY、GUANLAN_* 三项

python -m src.main --mode daily            # 抓昨天（约 2.5~3 小时）
python pipeline.py --no-push               # 只提炼+选条+存档 JSON，不推网站
python pipeline.py                         # 完整流程（推送到观澜）
python -m src.main --mode stats            # 库内状态
```

## 部署到服务器（root-189）

```bash
sudo mkdir -p /opt/shizheng && sudo chown $USER /opt/shizheng
rsync -av --exclude venv --exclude data ./ /opt/shizheng/   # 或 git clone
cd /opt/shizheng
python3 -m venv venv && ./venv/bin/pip install -r requirements.txt
cp .env.example .env && nano .env          # 填凭据
crontab -e                                  # 粘贴 deploy/crontab.example 内容
```

## 目录

```
src/       抓取核心（config/main/core/sources/refine/render）
bridge/    观澜对接（select_top10 选条 / render_html 渲染 / push_hotspots 推送）
pipeline.py  每日总入口
deploy/    crontab 示例、一键脚本、Dockerfile、只读自检脚本
tests/     离线单测（不触网）
data/      SQLite 库 + 原文存档 + 每日 JSON 存档（top10_YYYY-MM-DD.json）
```

## 测试与自检

```bash
./venv/bin/python -m unittest tests.test_htmltext tests.test_fetcher_delay   # 39 项离线单测
./venv/bin/python deploy/probe_delay.py       # 只读：打印各主机限速口径 + sitemap 去重结果
./venv/bin/python deploy/smoke_fetch.py       # 真实抓 1 篇：验证限速间隔与正文提取
./venv/bin/python deploy/repair_mojibake.py   # dry-run 列出乱码标题；加 --apply 才写库
```

## 已知坑（均为实测踩到，已在代码里留注释）

- **非贪婪正则会静默吃掉整版正文**：`<div class="rm_txt_con...">(.*?)</div>` 在正文嵌套的
  `<div class="bza">` 处就截断，正文变 0 字后被「长度 < 60」丢弃 —— 2026-09-29 那轮
  politics / world / society 三个频道 0 篇入库且**不报任何错**。现在统一走
  `core/htmltext.py` 的 `<div>` 深度配平扫描。
- **`apparent_encoding`（chardet）对文字稀疏的页面会猜错**：人民日报版面页被猜成
  MacCyrillic，标题变成 `еЕіиЊєеѓМж∞С...`。现在优先用 HTTP 头 / `<meta charset>`
  声明的字符集，多候选严格解码，并用 `fix_mojibake()` 兜底自愈。
- **正文里可能有 `<script>showPlayer({...})</script>`**：不先剥 script 就会把播放器 JS 存成正文。
- **版权行「本版责编：×××」也是链接**：会被当稿件抓进来（正文全是 URL）。已按标题前缀过滤，
  并要求正文汉字数 ≥ `MIN_BODY_CJK`。
- **限速语义**：`Crawl-delay` 按「两次请求的**发起时刻**」计（`_last_hit` 记发起时刻），
  所以实测间隔 ≥ 声明值；不要改成「上次响应结束到下次发起」。

## 合规

- **限速按主机生效**（robots.txt 的 `Crawl-delay` 本就是 per-host 指令）：
  抓取前懒加载该主机的 `robots.txt` 并缓存，**声明了就严格遵守**
  （`www.people.com.cn` / `www.people.cn` 声明 120 秒，sitemap 请求照此等待）；
  未声明（robots 404，或 culture/society 有 robots 但无该指令）则用保守默认
  `DEFAULT_DELAY=12s`（可用 `CRAWL_DEFAULT_DELAY` 覆盖）。
  实测 9 个文章子域均未声明 Crawl-delay，因此不再一刀切 120 秒。
- 串行不并发；每主机请求间加 0~30% 抖动；403/429 加长退避
- `LIMIT_PER_CHANNEL`（默认 60）控制每频道抓取上限，可用环境变量调整
- 内容仅个人学习用途，保留来源标注
