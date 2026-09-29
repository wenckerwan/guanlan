# 时政每日抓取 → 观澜网站

每天自动抓取《人民日报》全版面 + 人民网新增文章，提炼成考研政治考点推送到观澜网站；
**AI 筛选分析在网站服务端完成**（后台「每日时政」页可填 AI API、查看候选池、一键筛选/发布）。

**自包含、可移植**：整个目录可原样挪入观澜仓库（如 `guanlan/services/shizheng-crawler/`），
路径默认全部相对于本目录，配置全部走 `.env` / 环境变量。

## 流程

```
cron 03:30
  → src.main --mode daily        抓取（限速遵守 robots.txt，串行，约 80 分钟）
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

python -m src.main --mode daily            # 抓昨天（约 80 分钟）
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
deploy/    crontab 示例、一键脚本、Dockerfile
data/      SQLite 库 + 原文存档 + 每日 JSON 存档（top10_YYYY-MM-DD.json）
```

## 合规

- 严格遵守 `www.people.com.cn/robots.txt` 的 `Crawl-delay: 120`，串行不并发
- 内容仅个人学习用途，保留来源标注
