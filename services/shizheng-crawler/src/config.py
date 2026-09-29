# -*- coding: utf-8 -*-
"""全局配置

可移植性约定：
  * 所有路径默认自包含在本目录下（data/ logs/），可用环境变量覆盖
  * 自动加载工程根目录的 .env（不依赖 python-dotenv）
  * 敏感配置（LLM key、观澜 API 凭据）一律走环境变量，不写进代码
"""
import os
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent   # src/ 的上一级 = 工程根


def _load_dotenv(path: Path):
    """极简 .env 加载：KEY=VALUE，# 开头为注释；已存在的环境变量优先"""
    if not path.is_file():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, v = line.split("=", 1)
        os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))


_load_dotenv(ROOT / ".env")

# ---------- 限速（关键：严格遵守 robots.txt）----------
# www.people.com.cn/robots.txt 声明 Crawl-delay: 120
DELAY_PEOPLE = 120.0      # people.com.cn 主域及子域
DELAY_RMRB    = 12.0      # paper.people.com.cn（无 robots.txt，主动克制）
JITTER        = 0.3       # 随机抖动系数，避免完全固定的节奏

# ---------- 请求头 ----------
UA = ("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
      "(KHTML, like Gecko) Chrome/126.0 Safari/537.36")
HEADERS = {
    "User-Agent": UA,
    "Accept-Language": "zh-CN,zh;q=0.9",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
}
TIMEOUT = 25
RETRY = 3
RETRY_BACKOFF = 5.0

# ---------- 路径 ----------
DATA_DIR   = Path(os.getenv("SHIZHENG_DATA_DIR", ROOT / "data"))
RAW_DIR    = DATA_DIR / "raw"
OUT_DIR    = DATA_DIR / "out"
LOG_DIR    = Path(os.getenv("SHIZHENG_LOG_DIR", ROOT / "logs"))
DB_PATH    = DATA_DIR / "crawl.db"

# ---------- 观澜网站对接（每日推送 10 条时政）----------
GUANLAN_API_BASE   = os.getenv("GUANLAN_API_BASE", "").rstrip("/")
GUANLAN_ADMIN_EMAIL    = os.getenv("GUANLAN_ADMIN_EMAIL", "")
GUANLAN_ADMIN_PASSWORD = os.getenv("GUANLAN_ADMIN_PASSWORD", "")
GUANLAN_SUBJECT_ID = int(os.getenv("GUANLAN_SUBJECT_ID", "6"))  # 当代世界经济与政治(时政)
DAILY_TOP_N        = int(os.getenv("DAILY_TOP_N", "10"))

for d in (DATA_DIR, RAW_DIR, OUT_DIR, LOG_DIR):
    d.mkdir(parents=True, exist_ok=True)

# ---------- 数据源 ----------
# 人民日报电子版：每日版面列表
# 注意：node_01..node_08，用探测法确定实际版面数
RMRB_LAYOUT = "http://paper.people.com.cn/rmrb/pc/layout/{yyyymm}/{dd}/node_{page:02d}.html"
RMRB_CONTENT = "http://paper.people.com.cn/rmrb/pc/content/{yyyymm}/{dd}/{cid}"
RMRB_MAX_PAGE = 24

# 人民网 sitemap 增量
SITEMAP_INDEX = "http://www.people.cn/sitemap_index.xml"
SITEMAP_KEEP = [
    "politics", "world", "finance", "theory",
    "opinion", "legal", "culture", "society", "env",
]

# ---------- 关键词过滤（初筛，降低 LLM 成本）----------
KEYWORDS = [
    "习近平", "中共中央", "政治局", "全会",
    "国务院", "全国人大", "全国政协", "中央经济工作会议",
    "中央农村工作会议", "中央一号文件", "中国式现代化", "新质生产力",
    "高质量发展", "共同富裕", "全面深化改革", "十五五",
    "五年规划", "从严治党", "党的自我革命", "长征",
    "抗战", "遵义会议", "改革开放", "一国两制",
    "台湾", "全球发展倡议", "全球安全倡议", "全球文明倡议",
    "人类命运共同体", "联合国", "金砖", "上合",
    "APEC", "二十国集团", "G20", "全球南方",
    "中美", "中俄", "中欧", "中非",
    "一带一路", "乡村振兴", "粮食安全", "生态文明",
    "碳达峰", "碳中和", "依法治国", "爱国主义",
    "民族团结", "国家安全", "生态环境法典", "法典",
    "编纂", "绿色低碳发展", "污染防治", "生态保护",
    "美丽中国", "山水林田湖草沙", "碳排放权", "双碳",
    "重点整治", "系统治理", "重点攻坚", "协同治理",
    "降碳、减污、扩绿、增长", "航天", "人工智能", "量子",
    "芯片", "北斗",

]

# 排除词（体育、娱乐、广告）
EXCLUDE = ["男足", "女足", "跳水", "篮球", "足球", "中超", "CBA", "彩票", "广告", "遗失", "声明"]
