# -*- coding: utf-8 -*-
"""htmltext 正文提取与编码自愈的离线测试（不触网）。"""
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT))

from src.core import htmltext


# 人民网 politics 页的真实结构：rm_txt_con > bza > rm_txt_zw > p，且 p 内嵌 script
PEOPLE_POLITICS = """
<html><head><title>某重要报道--时政--人民网</title></head><body>
<div class="main"><div class="rm_nav cf" id="rm_topnav">
  <div class="layout rm_nav_con cf"><a href="/">首页</a><a href="/politics/">时政</a></div>
</div></div>
<div class="rm_txt_con cf">
  <div class="bza"><span></span></div>
  <div id="rm_txt_zw">
    <div class="box_pic"></div>
    <p> <script id="q_v_j-1" src="//video.people.cn/people-video.js"></script>
        <script>showPlayer({scriptId: 'q_v_j-1', videoInfo: [{src: 'x.mp4'}]});</script> </p>
    <p>新华社北京9月29日电 中共中央总书记、国家主席、中央军委主席近日对做好“三农”工作作出重要指示，强调要坚持农业农村优先发展，推动乡村全面振兴取得新进展。</p>
    <p>会议指出，当前和今后一个时期，要着力抓好粮食生产、耕地保护、农民增收三件大事，确保国家粮食安全和不发生规模性返贫。</p>
    <div class="edit"><p>（责编：袁勃、赵欣悦）</p></div>
  </div>
</div>
<div class="footer"><p>人 民 网 版 权 所 有 ，未 经 书 面 授 权 禁 止 使 用</p></div>
</body></html>
"""

# 人民日报电子版：ozoom 容器
RMRB_PAGE = """
<html><body><div class="content">
<div id="ozoom" style="text-align:left;">
  <p>本报讯 中共中央办公厅、国务院办公厅印发《关于推进新型城镇化建设的意见》，要求各地区各部门结合实际认真贯彻落实。</p>
  <p>意见提出，到2030年常住人口城镇化率达到70%左右，农业转移人口市民化质量显著提升。</p>
</div>
<div class="edit">《人民日报》2026年09月29日 第01版</div>
</div></body></html>
"""

NO_CONTAINER = """
<html><body>
<p>这是一段没有容器包裹的正文，长度超过十五个字符以便通过过滤。</p>
<p>第二段同样足够长，用来验证整页兜底路径能够正常拼接多段文本。</p>
</body></html>
"""


class ExtractBodyTest(unittest.TestCase):
    def test_people_nested_div_not_truncated(self):
        """回归：旧非贪婪正则会在 <div class="bza"> 处截断，正文变 0 字。"""
        body = htmltext.extract_body(PEOPLE_POLITICS)
        self.assertIn("三农", body)
        self.assertIn("粮食安全", body)
        self.assertGreater(len(body), 60)

    def test_script_not_in_body(self):
        body = htmltext.extract_body(PEOPLE_POLITICS)
        for junk in ("showPlayer", "scriptId", "<script", "videoInfo"):
            self.assertNotIn(junk, body)

    def test_footer_and_edit_excluded(self):
        body = htmltext.extract_body(PEOPLE_POLITICS)
        self.assertNotIn("版权所有", body)
        self.assertNotIn("责编", body)

    def test_ozoom_container(self):
        body = htmltext.extract_body(RMRB_PAGE)
        self.assertIn("新型城镇化", body)
        self.assertNotIn("《人民日报》", body)

    def test_whole_page_fallback(self):
        body = htmltext.extract_body(NO_CONTAINER)
        self.assertIn("整页兜底", body)

    def test_empty(self):
        self.assertEqual(htmltext.extract_body(""), "")
        self.assertEqual(htmltext.extract_body(None), "")


class MatchDivTest(unittest.TestCase):
    def test_balanced(self):
        html = '<div id="a"><div>b</div><p>x</p></div><div>tail</div>'
        inner = htmltext.match_div_inner(html, 0)
        self.assertEqual(inner, "<div>b</div><p>x</p>")

    def test_unbalanced_returns_rest(self):
        html = '<div id="a"><p>x</p>'
        self.assertEqual(htmltext.match_div_inner(html, 0), "<p>x</p>")


class MojibakeTest(unittest.TestCase):
    ORIGINAL = "关注富民的双重功夫（前沿观察）"

    def test_mac_cyrillic_roundtrip(self):
        """实测错源：chardet 猜成 MacCyrillic（映射完整，可无损回解）。"""
        broken = self.ORIGINAL.encode("utf-8").decode("mac-cyrillic")
        self.assertNotEqual(broken, self.ORIGINAL)
        self.assertTrue(htmltext.RE_MOJIBAKE.search(broken))
        self.assertEqual(htmltext.fix_mojibake(broken), self.ORIGINAL)

    def test_latin1_roundtrip(self):
        broken = self.ORIGINAL.encode("utf-8").decode("latin-1")
        self.assertEqual(htmltext.fix_mojibake(broken), self.ORIGINAL)

    def test_cp1251_roundtrip(self):
        broken = self.ORIGINAL.encode("utf-8").decode("cp1251", "replace")
        fixed = htmltext.fix_mojibake(broken)
        if "\ufffd" not in broken:      # 未丢字节时应逐字还原
            self.assertEqual(fixed, self.ORIGINAL)
        else:                           # 丢字节时至少要能修出中文
            self.assertTrue(htmltext.RE_CJK.search(fixed), fixed)

    def test_real_server_sample(self):
        """服务器库里实际出现的乱码标题（id=64），必须能修回中文。"""
        broken = "еЕіиЊєеѓМж∞СзЪДдЄЙйЗНеКЯе§ЂпЉИеЙНж≤љиІВеѓЯпЉЙ"
        fixed = htmltext.fix_mojibake(broken)
        self.assertTrue(htmltext.RE_CJK.search(fixed), f"未修回中文: {fixed!r}")
        self.assertFalse(htmltext.RE_MOJIBAKE.search(fixed), fixed)
        self.assertIn("富民", fixed)
        self.assertIn("观察", fixed)

    def test_lossy_replace_is_not_crash(self):
        """requests 用 errors='replace' 解码时会留下 U+FFFD，此时无法无损回解，
        但绝不能抛异常，也不能把乱码改成更糟的东西。"""
        broken = self.ORIGINAL.encode("utf-8").decode("cp1252", "replace")
        self.assertIn("\ufffd", broken)
        self.assertEqual(htmltext.fix_mojibake(broken), broken)

    def test_clean_text_untouched(self):
        for s in ("正常的中文标题 Normal Title 2026",
                  "《人民日报》2026年09月29日 第02版",
                  "Café résumé naïve — 2026"):
            self.assertEqual(htmltext.fix_mojibake(s), s)


class DecodeHtmlTest(unittest.TestCase):
    def test_utf8_declared_wrong_by_chardet(self):
        raw = "中共中央召开会议研究经济工作".encode("utf-8")
        out = htmltext.decode_html(raw, declared="utf-8", apparent="ISO-8859-1")
        self.assertEqual(out, "中共中央召开会议研究经济工作")

    def test_gb18030_page(self):
        raw = "人民日报电子版内容测试".encode("gb18030")
        out = htmltext.decode_html(raw, declared="", apparent="GB2312")
        self.assertIn("人民日报", out)

    def test_mojibake_self_heal(self):
        """页面声明缺失、chardet 猜成 MacCyrillic 时应自愈成中文。"""
        raw = "推进乡村全面振兴".encode("utf-8")
        broken = raw.decode("mac-cyrillic")
        out = htmltext.decode_html(broken.encode("mac-cyrillic"),
                                   declared="", apparent="MacCyrillic")
        self.assertEqual(out, "推进乡村全面振兴")

    def test_empty(self):
        self.assertEqual(htmltext.decode_html(b""), "")


class DeclaredCharsetTest(unittest.TestCase):
    def test_from_header(self):
        self.assertEqual(
            htmltext.declared_charset({"Content-Type": "text/html; charset=UTF-8"}),
            "UTF-8")

    def test_from_meta(self):
        head = b'<html><head><meta http-equiv="Content-Type" content="text/html; charset=gb2312"></head>'
        self.assertEqual(htmltext.declared_charset(None, head).lower(), "gb2312")

    def test_none(self):
        self.assertEqual(htmltext.declared_charset({"Content-Type": "text/html"}, b"<html>"), "")


class CjkCountTest(unittest.TestCase):
    def test_counts_only_han(self):
        self.assertEqual(htmltext.cjk_count("筑牢国防根基 2026 ABC"), 6)

    def test_link_soup_is_low(self):
        """回归：id 40/68 那种「正文 170+ 字但全是 URL」的假稿。"""
        body = "3018374110http://paper.people.com.cn/rmrb/pc/content/202609/29/x.html"
        self.assertLess(htmltext.cjk_count(body), 30)

    def test_empty(self):
        self.assertEqual(htmltext.cjk_count(""), 0)
        self.assertEqual(htmltext.cjk_count(None), 0)


class BoilerplateTest(unittest.TestCase):
    """版式残留（日期来源行 / 责编行）过滤。

    实测 2026-09-30：people 库 533 篇正文里有 59 篇首段是
    「2025年03月07日14:51 来源：人民网」这种版式行，不是正文。
    """

    NOISE = (
        "2025年03月07日14:51 来源：人民网",
        "2026年09月29日 08:32 来源：新华社",
        "来源：人民日报",
        "责编：袁勃、赵欣悦",
        "责任编辑：张三",
        "编辑：李四",
        "本版责编：吕钟正 吴 凯",
        "2026年09月29日 第01版",
    )

    REAL = (
        # 真正的文首句，绝不能被误杀
        "2025年9月29日，中共中央政治局召开会议，分析研究当前经济形势和经济工作。",
        "2026年9月29日，庆祝中华人民共和国成立77周年招待会在北京人民大会堂举行。",
        "来源可靠的大国重器项目在今年取得突破性进展，为后续工程奠定了基础。",
        "责编在会议上强调，要把好稿件的政治关和质量关，确保导向正确。",
        "新华社北京9月29日电 中共中央总书记、国家主席、中央军委主席近日作出重要指示。",
    )

    def test_noise_detected(self):
        for t in self.NOISE:
            self.assertTrue(htmltext.is_boilerplate(t), t)

    def test_real_sentences_kept(self):
        for t in self.REAL:
            self.assertFalse(htmltext.is_boilerplate(t), t)

    def test_plain_body_not_boilerplate(self):
        self.assertFalse(htmltext.is_boilerplate(
            "会议指出，要着力抓好粮食生产、耕地保护、农民增收三件大事。"))

    def test_paragraphs_skips_dateline(self):
        seg = """
        <p>2025年03月07日14:51 来源：人民网</p>
        <p>这是真正的正文第一段，长度足够通过最小字数过滤这一关。</p>
        <p>（责编：袁勃、赵欣悦）</p>
        <p>这是真正的正文第二段，用于验证只留下内容段落。</p>
        """
        parts = htmltext.paragraphs(seg)
        self.assertEqual(parts, [
            "这是真正的正文第一段，长度足够通过最小字数过滤这一关。",
            "这是真正的正文第二段，用于验证只留下内容段落。",
        ])

    def test_extract_body_first_line_is_content(self):
        html = ('<div id="rm_txt_zw">'
                '<p>2026年09月29日 08:32 来源：人民网</p>'
                '<p>本报北京9月29日电 全国民族团结进步表彰大会今日在京召开。</p>'
                '</div>')
        body = htmltext.extract_body(html)
        self.assertTrue(body.startswith("本报北京"), body)
        self.assertNotIn("来源：人民网", body)


class FooterTest(unittest.TestCase):
    """人民网页脚行过滤。

    实测：页面没有正文容器时走整页 `<p>` 兜底，会把页脚扫进正文
    （「每日一闻/每日一句」这类短条目 6 篇尾部都带着许可证号与版权行）。
    """

    FOOTER = (
        "人民日报社概况 | 关于人民网 | 报社招聘 | 招聘英才 | 广告服务 | 合作加盟 | 供稿服务 | 数据服务 | 网站声明 | 网站律师 | 信息保护 | 联系我们",
        "人民日报违法和不良信息举报电话：010-65363263 举报邮箱：jubao@people.cn",
        "人民网服务邮箱：kf@people.cn 违法和不良信息举报电话：010-65363636 举报邮箱：rmwjubao@people.cn",
        "互联网新闻信息服务许可证10120170001 | 增值电信业务经营许可证B1-20060139 | 广播电视节目制作经营许可证（广媒）字第172号",
        "信息网络传播视听节目许可证0104065 | 网络文化经营许可证 京网文[2023]4961-141号 | 京ICP证000006号",
        "人 民 网 版 权 所 有 ，未 经 书 面 授 权 禁 止 使 用Copyright © 1997-2026 by www.people.com.cn. all rights reserved",
    )

    def test_footer_detected(self):
        for t in self.FOOTER:
            self.assertTrue(htmltext.is_footer(t), t[:50])

    def test_normal_text_not_footer(self):
        """谈版权/许可的正规稿件不能被误判成页脚。"""
        for t in ("著作权法修改草案强化了数字环境下的版权保护，明确了平台责任。",
                  "市场监管总局简化了食品经营许可证的办理流程，压缩审批时限。",
                  "会议强调要加强网络空间治理，压实平台主体责任，保护未成年人。"):
            self.assertFalse(htmltext.is_footer(t), t[:40])

    def test_paragraphs_drops_footer(self):
        seg = ("<p>这是真正的正文段落，长度足够通过最小字数过滤这一关卡。</p>"
               "<p>人 民 网 版 权 所 有 ，未 经 书 面 授 权 禁 止 使 用</p>"
               "<p>互联网新闻信息服务许可证10120170001 | 增值电信业务经营许可证B1-20060139</p>")
        self.assertEqual(htmltext.paragraphs(seg),
                         ["这是真正的正文段落，长度足够通过最小字数过滤这一关卡。"])

    def test_whole_page_fallback_excludes_footer(self):
        """回归：没有正文容器时整页兜底也不能带页脚。"""
        html = ("<html><body>"
                "<p>大熊猫“平平”“福双”抵达美国动物园，开启新一轮合作保护研究。</p>"
                "<p>中美双方将围绕大熊猫保护、疾病防控等议题开展联合研究。</p>"
                "<p>人民日报社概况 | 关于人民网 | 报社招聘 | 招聘英才 | 广告服务 | 联系我们</p>"
                "<p>人 民 网 版 权 所 有 ，未 经 书 面 授 权 禁 止 使 用</p>"
                "</body></html>")
        body = htmltext.extract_body(html)
        self.assertIn("大熊猫", body)
        self.assertFalse(htmltext.is_footer(body), body[-80:])
        self.assertNotIn("报社招聘", body)


class TitleCleanTest(unittest.TestCase):
    def setUp(self):
        from src.sources import people_sitemap as ps
        self.ps = ps

    def strip(self, s):
        return self.ps._clean_title(s)

    def test_channel_word_not_hardcoded(self):
        """频道段是「教育」「经济·科技」这类任意词，也要能整段剥掉。"""
        cases = {
            "新时代全国史学期刊论坛在京召开--理论-中国共产党新闻网":
                "新时代全国史学期刊论坛在京召开",
            "这一次，他们把电影机装进口袋--经济·科技--人民网":
                "这一次，他们把电影机装进口袋",
            "原来你是这样的人大代表｜灭火英雄跨界守护文化根脉--2024年全国两会--人民网":
                "原来你是这样的人大代表｜灭火英雄跨界守护文化根脉",
            "一体推进反腐败斗争取得压倒性胜利-时政-人民网":
                "一体推进反腐败斗争取得压倒性胜利",
            # 实测库里 id=122：短标题也要能剥（早期用 >=8 字门槛会漏掉它）
            "如何读《孟子》--理论-中国共产党新闻网": "如何读《孟子》",
        }
        for raw, want in cases.items():
            self.assertEqual(self.strip(raw), want, raw)

    def test_fullwidth_bar_inside_title_survives(self):
        """正文标题里的全角竖线「丨」不能被当分隔符吃掉。"""
        raw = ("北京高校新时代立德树人工程创新实践专题报道丨中国农业大学："
               "凝心铸魂兴稼穑 春风化雨育英才--教育--人民网")
        self.assertEqual(
            self.strip(raw),
            "北京高校新时代立德树人工程创新实践专题报道丨中国农业大学："
            "凝心铸魂兴稼穑 春风化雨育英才")

    def test_no_site_suffix_untouched(self):
        for s in ("一体推进“三不腐”（金台潮声）",
                  "专题报道丨中国农业大学：凝心铸魂兴稼穑",
                  "筑牢国防根基 激发奋进力量"):
            self.assertEqual(self.strip(s), s)

    def test_short_title_keeps_suffix(self):
        s = "短标题--时政--人民网"
        self.assertEqual(self.strip(s), s)

    def test_mojibake_title_healed(self):
        broken = "筑牢国防根基 激发奋进力量".encode("utf-8").decode("mac-cyrillic")
        self.assertEqual(self.strip(broken), "筑牢国防根基 激发奋进力量")


if __name__ == "__main__":
    unittest.main(verbosity=2)
