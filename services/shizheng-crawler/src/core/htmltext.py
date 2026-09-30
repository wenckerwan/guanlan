# -*- coding: utf-8 -*-
"""HTML → 正文纯文本的公共工具（people_sitemap / rmrb 共用）。

为什么需要它（2026-09-30 实测踩到的三个坑）：
  1. `<div class="rm_txt_con cf">(.*?)</div>` 这类**非贪婪**正则会在第一个嵌套
     `</div>`（如 `<div class="bza">`）处截断，正文全丢 → politics/world/society
     三个频道整轮 0 篇入库，且不报错（静默丢数据）。因此这里改用 `<div>` 配平扫描。
  2. 人民网正文的 `<p>` 里嵌了 `<script>showPlayer({...})</script>`，不先剥
     script/style 就会把 JS 当正文存库。
  3. `apparent_encoding`（chardet/charset_normalizer）对文字稀疏的版面页会猜错编码
     （库里 id=64 实测猜成 MacCyrillic），产生 `еЕіиЊєеѓМж∞С...` 这类乱码标题。
     这里提供 `fix_mojibake` 做特征识别 + 回解码自愈，`decode_html` 则优先用
     header/meta 声明的字符集，从源头避免猜错。
"""
import re

# ---------- 噪声剥除 ----------
RE_SCRIPT = re.compile(r"<(script|style|noscript)\b[^>]*>.*?</\1\s*>", re.S | re.I)
RE_COMMENT = re.compile(r"<!--.*?-->", re.S)
RE_TAG = re.compile(r"<[^>]+>")
RE_WS = re.compile(r"\s+")
RE_P = re.compile(r"<p[^>]*>(.*?)</p>", re.S)
RE_DIV_OPEN = re.compile(r"<div\b", re.I)
RE_DIV_ANY = re.compile(r"<div\b|</div\s*>", re.I)
RE_ONLY_DIGITS = re.compile(r"^[\d\s.,、-]+$")
RE_CJK = re.compile(r"[\u4e00-\u9fff]")
# mojibake 特征：连续出现拉丁补充区（含 C1 控制区 \u0080-\u009f，latin-1 型乱码的
# 招牌）/西里尔区/数学符号区的怪字符，正常中文稿不会有。
# 触发得宽一点是安全的：fix_mojibake 只有在「回解码后出现中文且乱码特征消失」时
# 才采纳结果，否则原样返回。
RE_MOJIBAKE = re.compile(
    r"[\u0080-\u00ff\u0100-\u017f\u0400-\u04ff\u2010-\u203a\u2200-\u22ff]{3,}")

# UTF-8 字节被错误解码时最常见的几个「错源」编码。
# 实测（2026-09-30，库里 id=64 标题）：chardet 对文字稀疏的版面页猜成了
# **MacCyrillic**，于是 `еЕіиЊєеѓМж∞С...` 要按 mac-cyrillic 回编码才解得开；
# cp1252 反而解不动。所以候选里必须带上 mac-cyrillic / cp1251。
MOJIBAKE_CODECS = ("mac-cyrillic", "cp1251", "cp1252", "latin-1")

# 常见正文容器，按优先级尝试
CONTAINERS_ARTICLE = [
    re.compile(r'<div[^>]*id="ozoom"[^>]*>', re.I),
    re.compile(r'<div[^>]*id="rm_txt_zw"[^>]*>', re.I),
    re.compile(r'<div[^>]*class="[^"]*rm_txt_con[^"]*"[^>]*>', re.I),
]


def clean_text(s: str) -> str:
    """去标签 + 实体 + 折叠空白。"""
    s = RE_TAG.sub("", s)
    for a, b in (("&nbsp;", " "), ("&amp;", "&"), ("&quot;", '"'),
                 ("&ldquo;", "“"), ("&rdquo;", "”"), ("&mdash;", "—"),
                 ("&hellip;", "…"), ("&#39;", "'"), ("&lt;", "<"), ("&gt;", ">")):
        s = s.replace(a, b)
    return RE_WS.sub(" ", s).strip()


def strip_noise(html: str) -> str:
    """剥掉 script/style/noscript/注释，避免 JS 混进正文。"""
    html = RE_SCRIPT.sub(" ", html)
    return RE_COMMENT.sub(" ", html)


def match_div_inner(html: str, open_tag_start: int) -> str:
    """从开标签位置做 `<div>`/`</div>` 配平，返回容器**内部** HTML。

    配平失败（页面截断等）时退化为「开标签之后到文末」。
    """
    gt = html.find(">", open_tag_start)
    if gt < 0:
        return ""
    inner_start = gt + 1
    depth = 0
    for m in RE_DIV_ANY.finditer(html, open_tag_start):
        if m.group(0).lower().startswith("</"):
            depth -= 1
            if depth == 0:
                return html[inner_start:m.start()]
        else:
            depth += 1
    return html[inner_start:]


def paragraphs(seg: str, min_len: int = 15, skip_prefixes=()) -> list:
    """从 HTML 片段里抽 `<p>` 文本，过滤图注/页脚/纯数字。"""
    out = []
    for raw in RE_P.findall(seg):
        t = clean_text(raw)
        if len(t) < min_len:
            continue
        if RE_ONLY_DIGITS.match(t):
            continue
        if any(t.startswith(p) for p in skip_prefixes):
            continue
        out.append(t)
    return out


def _text_of(seg: str, skip_prefixes=()) -> str:
    """容器内没有 `<p>` 时（少数版面用 `<br>` 排版）退化为整块去标签。"""
    t = clean_text(seg)
    if any(t.startswith(p) for p in skip_prefixes):
        return ""
    return t


def extract_body(html: str, containers=None, min_len: int = 15,
                 skip_prefixes=("《人民日报》", "人民日报 (20")) -> str:
    """按容器优先级提取正文；全部失败时退回整页 `<p>` 扫描。"""
    if not html:
        return ""
    cleaned = strip_noise(html)
    pats = CONTAINERS_ARTICLE if containers is None else containers

    for pat in pats:
        m = pat.search(cleaned)
        if not m:
            continue
        inner = match_div_inner(cleaned, m.start())
        if not inner:
            continue
        parts = paragraphs(inner, min_len=min_len, skip_prefixes=skip_prefixes)
        if parts:
            return "\n".join(parts)
        fallback = _text_of(inner, skip_prefixes)
        if len(fallback) >= 60:
            return fallback

    # 整页兜底
    parts = paragraphs(cleaned, min_len=min_len, skip_prefixes=skip_prefixes)
    return "\n".join(parts)


def fix_mojibake(text: str) -> str:
    """UTF-8 字节被按 mac-cyrillic/cp1251/cp1252/latin-1 解码后的自愈。

    只有当回解码结果**含中文且不再命中乱码特征**时才采纳，否则原样返回，
    保证正常中英文标题绝不会被误伤。
    """
    if not text or not RE_MOJIBAKE.search(text):
        return text
    for enc in MOJIBAKE_CODECS:
        try:
            fixed = text.encode(enc, "strict").decode("utf-8", "strict")
        except (UnicodeEncodeError, UnicodeDecodeError, LookupError):
            continue
        if RE_CJK.search(fixed) and not RE_MOJIBAKE.search(fixed):
            return fixed
    return text


def cjk_count(text: str) -> int:
    """汉字个数。用于识别「链接堆 / 图注 / 版权行」这类假正文——
    实测 2026-09-29 有 2 条正文长 170+ 字但几乎全是 URL（id 40/68）。"""
    return len(RE_CJK.findall(text or ""))


def declared_charset(headers=None, head_bytes: bytes = b"") -> str:
    """从 HTTP 头或 HTML 头部 meta 里取声明的字符集，取不到返回空串。"""
    if headers:
        ct = headers.get("Content-Type", "") if hasattr(headers, "get") else str(headers)
        m = re.search(r"charset=\s*([\w-]+)", ct, re.I)
        if m:
            return m.group(1).strip()
    if head_bytes:
        head = head_bytes[:4096].decode("ascii", "ignore")
        m = re.search(r"""<meta[^>]+charset\s*=\s*["']?\s*([\w-]+)""", head, re.I)
        if m:
            return m.group(1).strip()
        m = re.search(r"""encoding\s*=\s*["']([\w-]+)["']""", head, re.I)
        if m:
            return m.group(1).strip()
    return ""


def decode_html(content: bytes, declared: str = "", apparent: str = "") -> str:
    """多候选严格解码：声明字符集 → chardet 猜测 → utf-8 → gb18030。

    取第一个能严格解码且含中文、无替换字符的结果；都不理想则用第一个成功的。
    """
    if not content:
        return ""
    candidates, seen = [], set()
    for enc in (declared, apparent, "utf-8", "gb18030"):
        if not enc:
            continue
        key = enc.lower().replace("_", "-")
        if key in seen:
            continue
        seen.add(key)
        candidates.append(enc)

    first_ok = None
    for enc in candidates:
        try:
            text = content.decode(enc, "strict")
        except (UnicodeDecodeError, LookupError):
            continue
        text = fix_mojibake(text)
        if first_ok is None:
            first_ok = text
        if RE_CJK.search(text) and "\ufffd" not in text:
            return text
    if first_ok is not None:
        return first_ok
    return fix_mojibake(content.decode("utf-8", "replace"))
