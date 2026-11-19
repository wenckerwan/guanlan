"""Check public API routing, BFF auth boundaries and guest analysis access through Nginx."""

import argparse
import json
from urllib.error import HTTPError
from urllib.parse import urlencode
from urllib.request import urlopen

RELEASE = "2012-2026-649d1f95"
RETIRED = ("2012-2026-45208c3f", "2012-2026-v2-4f5624ad")

def require(condition, message):
    if not condition:
        raise RuntimeError(message)


def check(base):
    def get(path, expected):
        try:
            response = urlopen(base.rstrip("/") + path, timeout=15)
        except HTTPError as error:
            response = error
        with response:
            require(response.status == expected, f"{path}: expected HTTP {expected}, got {response.status}")
            return response.read().decode("utf-8")

    health = json.loads(get("/api/v1/health", 200))
    require(health.get("data", {}).get("db") == "ok", "API health endpoint cannot reach the database")
    get("/api/v1/home", 200)
    get("/api/v1/auth/me", 401)
    api = "/api/v1/analysis"
    rows = json.loads(get(api, 200))["data"]
    require(len(rows) == 11 and rows[0]["slug"] == RELEASE, "Wrong analysis count or ordering")
    require([row.get("locked") for row in rows] == [False] * 3 + [True] * 8, "Guest list quota changed")
    category = urlencode({"category": "选择题规律"})
    require(len(json.loads(get(f"{api}?{category}", 200))["data"]) == 4, "Wrong analysis category count")
    detail = json.loads(get(f"{api}/{RELEASE}", 200))["data"]
    require(len(detail["outline"]) == 37, "Wrong release outline count")
    for row in rows[:3]:
        get(f"{api}/{row['slug']}", 200)
    get(f"{api}/{rows[3]['slug']}", 403)
    get("/api/v1/admin/overview", 401)
    for slug in RETIRED:
        archived = json.loads(get(f"{api}/{slug}", 410))
        require(archived.get("code") == "ANALYSIS_VERSION_ARCHIVED", f"{slug}: wrong archive response")
        require(archived.get("replacementSlug") == RELEASE, f"{slug}: wrong replacement slug")
        html = get(f"/analysis/{slug}", 200)
        require("此版本已下架" in html and f'href="/analysis/{RELEASE}"' in html, f"{slug}: archive page missing")
    print("PASS production gateway: health, guest quota, session boundary, and both archive links")


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("base_url")
    check(parser.parse_args().base_url)
