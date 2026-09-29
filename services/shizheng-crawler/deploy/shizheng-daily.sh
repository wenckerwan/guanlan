#!/usr/bin/env bash
# 每日时政全流程：抓取 → 提炼 → 选 N 条 → 推送观澜网站
# 由 cron 调用；失败/空结果会触发 ALERT_WEBHOOK 告警。
set -uo pipefail

cd "$(dirname "$0")/.."
PY="./venv/bin/python"
[ -x "$PY" ] || PY="python3"

echo "===== $(date '+%F %T') daily 开始 ====="

"$PY" -m src.main --mode daily
crawl_rc=$?

"$PY" pipeline.py
pipe_rc=$?

# 心跳：全流程结束 ping 一次（脚本层；pipeline 内部另有空结果告警）
if [ -n "${HEARTBEAT_URL:-}" ] && [ $crawl_rc -eq 0 ] && [ $pipe_rc -eq 0 ]; then
  curl -fsS -m 10 "$HEARTBEAT_URL" >/dev/null || true
fi

echo "===== $(date '+%F %T') daily 结束 crawl=$crawl_rc pipeline=$pipe_rc ====="
[ $crawl_rc -eq 0 ] && [ $pipe_rc -le 1 ]
