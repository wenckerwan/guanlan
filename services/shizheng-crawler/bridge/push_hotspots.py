# -*- coding: utf-8 -*-
"""把选出的时政条目推送到观澜网站后台（POST /api/v1/admin/hotspots）。

幂等策略：hotspots.title 有数据库唯一约束，推送前先拉后台列表，
标题已存在则跳过（create-only，不覆盖人工在后台改过的内容）。
"""
import logging

import requests

log = logging.getLogger("push")

TIMEOUT = 30
# 单次候选推送条数：太大撞 gateway 的 client_max_body_size（实测 337 条 → HTTP 413）
CANDIDATE_BATCH = 40


class GuanlanClient:
    def __init__(self, api_base: str, email: str, password: str):
        if not api_base:
            raise ValueError("未配置 GUANLAN_API_BASE")
        self.base = api_base.rstrip("/")
        self.token = self._login(email, password)

    def _login(self, email: str, password: str) -> str:
        r = requests.post(f"{self.base}/api/v1/auth/login",
                          json={"email": email, "password": password},
                          timeout=TIMEOUT)
        r.raise_for_status()
        token = (r.json().get("data") or {}).get("token", "")
        if not token:
            raise RuntimeError("登录成功但未返回 token")
        log.info("观澜后台登录成功")
        return token

    def _headers(self) -> dict:
        return {"Authorization": f"Bearer {self.token}",
                "Content-Type": "application/json"}

    def existing_titles(self) -> set:
        r = requests.get(f"{self.base}/api/v1/admin/hotspots",
                         headers=self._headers(), timeout=TIMEOUT)
        r.raise_for_status()
        return {(item.get("title") or "").strip()
                for item in (r.json().get("data") or [])}

    def create_hotspot(self, payload: dict) -> bool:
        r = requests.post(f"{self.base}/api/v1/admin/hotspots",
                          headers=self._headers(), json=payload, timeout=TIMEOUT)
        if r.status_code in (200, 201):
            return True
        log.warning("推送失败 HTTP %s：%s | %s",
                    r.status_code, payload.get("title", "")[:40], r.text[:200])
        return False

    # ---------- 服务端 AI 筛选（需观澜 shizheng 补丁）----------

    def push_candidates(self, date: str, items: list):
        """**分批**推送当天候选到候选池。返回 (ok, 汇总结果)；404 表示补丁未部署。

        为什么分批：2026-09-30 修好正文提取后候选从 98 条涨到 337 条，一次性 POST
        直接撞 gateway 的 `client_max_body_size`，返回 **413 Request Entity Too Large**，
        整轮推送全废。服务端按 (日期, 标题) upsert，所以分批/重试都是幂等的。
        """
        total = {"created": 0, "updated": 0, "skipped": 0}
        batches = [items[i:i + CANDIDATE_BATCH]
                   for i in range(0, len(items), CANDIDATE_BATCH)]
        for idx, chunk in enumerate(batches, 1):
            if not chunk:
                continue
            r = requests.post(f"{self.base}/api/v1/admin/shizheng/candidates",
                              headers=self._headers(),
                              json={"date": date, "items": chunk}, timeout=120)
            if r.status_code == 404:
                return False, None
            r.raise_for_status()
            data = r.json().get("data") or {}
            for k in total:
                if isinstance(data.get(k), int):
                    total[k] += data[k]
            log.info("候选已推送 第 %d/%d 批（%d 条）：%s",
                     idx, len(batches), len(chunk), data)
        log.info("候选推送合计：%s", total)
        return True, total

    def screen(self, date: str, top: int, auto: bool = True):
        """触发服务端 AI 筛选（auto=True 时筛完自动发布）"""
        r = requests.post(f"{self.base}/api/v1/admin/shizheng/screen",
                          headers=self._headers(),
                          json={"date": date, "top": top, "auto": auto},
                          timeout=300)
        r.raise_for_status()
        data = r.json().get("data") or {}
        log.info("服务端筛选完成：候选 %(total)s 入选 %(selected)s 发布 %(published)s",
                 {"total": data.get("total"),
                  "selected": len(data.get("selected") or []),
                  "published": data.get("published")})
        return data


def push_items(client: GuanlanClient, payloads: list) -> dict:
    """返回 {'created': n, 'skipped': n, 'failed': n}"""
    existing = client.existing_titles()
    result = {"created": 0, "skipped": 0, "failed": 0}
    for p in payloads:
        title = (p.get("title") or "").strip()
        if title in existing:
            log.info("已存在，跳过：%s", title[:50])
            result["skipped"] += 1
            continue
        if client.create_hotspot(p):
            log.info("已推送：%s", title[:50])
            existing.add(title)
            result["created"] += 1
        else:
            result["failed"] += 1
    return result
