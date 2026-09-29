# -*- coding: utf-8 -*-
"""提炼层：把新闻正文转成考研政治格式。

两种后端：
  A. llm    —— 调用 LLM API（推荐，质量高）
  B. rule   —— 纯规则兜底（无 API 时可用，只做事实抽取）

输出统一为结构化 JSON，供渲染层使用。
"""
import json, logging, os, re, textwrap
from ..config import KEYWORDS

log = logging.getLogger("refine")

PROMPT_SYSTEM = """你是考研政治时政编辑。把给定的人民日报/人民网文章，转成考研政治复习素材。

严格输出 JSON，不要任何解释文字，结构如下：
{
  "title": "材料标题（保留原文，可精简）",
  "date": "YYYY-MM-DD",
  "priority": "绝高|极高|很高|高|中高|中",
  "module": "马原|习思想|史纲|思法|当代|时政",
  "type": "元首外交|中央会议|法律文件|纪念活动|倡议周年|经济部署|文化活动|其他",
  "facts": ["必背事实，逐条，含具体数字与日期"],
  "fixed_phrases": ["必须逐字准确的固定表述，保持原文措辞"],
  "exam_points": ["可能出题的角度"],
  "traps": ["易混淆点，如与相近提法的区别"]
}

要求：
1. fixed_phrases 必须是原文措辞，不得改写、不得合并。
2. 只提取与考研政治相关的信息，无关内容丢弃。
3. facts 必须可核查，不得推断。
4. 若材料与考研政治无关，返回 {"skip": true}。"""

PROMPT_USER = """日期：{date}
标题：{title}
来源：{source} / {channel}

正文：
{body}
"""


def _backend():
    key = os.getenv("LLM_API_KEY") or os.getenv("OPENAI_API_KEY")
    if not key:
        return None
    return {
        "key": key,
        "base": os.getenv("LLM_BASE_URL", "https://api.openai.com/v1"),
        "model": os.getenv("LLM_MODEL", "gpt-4o-mini"),
    }


def refine_llm(art: dict, cfg: dict) -> dict:
    import requests
    payload = {
        "model": cfg["model"],
        "messages": [
            {"role": "system", "content": PROMPT_SYSTEM},
            {"role": "user", "content": PROMPT_USER.format(
                date=art.get("publish_date", ""), title=art.get("title", ""),
                source=art.get("source", ""), channel=art.get("channel", ""),
                body=(art.get("body") or "")[:6000])},
        ],
        "temperature": 0.2,
        "response_format": {"type": "json_object"},
    }
    r = requests.post(f"{cfg['base']}/chat/completions",
                      headers={"Authorization": f"Bearer {cfg['key']}",
                               "Content-Type": "application/json"},
                      json=payload, timeout=120)
    r.raise_for_status()
    content = r.json()["choices"][0]["message"]["content"]
    return json.loads(content)


def refine_rule(art: dict) -> dict:
    """规则兜底：只做事实与关键词抽取，不做语义提炼"""
    body = art.get("body") or ""
    sents = re.split(r"(?<=[。；！])", body)
    facts = [s.strip() for s in sents if 20 < len(s.strip()) < 200][:12]
    hits = [k for k in KEYWORDS if k in body]
    phrases = []
    for pat in [r"坚持[^，。；]{4,40}", r"必须[^，。；]{4,40}",
                r"首次[^，。；]{4,40}", r"标志着[^，。；]{4,40}"]:
        phrases += re.findall(pat, body)[:4]
    return {
        "title": art.get("title", ""),
        "date": art.get("publish_date", ""),
        "priority": "中",
        "module": "时政",
        "type": "其他",
        "facts": facts,
        "fixed_phrases": phrases[:8],
        "exam_points": [],
        "traps": [],
        "_rule_based": True,
        "_keywords": hits,
    }


def refine(art: dict) -> dict:
    cfg = _backend()
    if cfg:
        try:
            return refine_llm(art, cfg)
        except Exception as e:
            log.warning("LLM 提炼失败，降级为规则模式：%s", str(e)[:100])
    return refine_rule(art)
