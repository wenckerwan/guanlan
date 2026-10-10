#!/bin/bash
B=http://localhost:8080/api/v1
code() { curl -s -o /tmp/out -w '%{http_code}' "$@"; }

echo "=== 1. 基础 ==="
printf 'health          %s  %s\n' "$(code $B/health)" "$(cat /tmp/out)"
printf 'home            %s\n' "$(code $B/home)"
head -c 700 /tmp/out; echo
printf 'stats/overview  %s\n' "$(code $B/stats/overview)"
head -c 300 /tmp/out; echo

echo; echo "=== 2. 学科 ==="
printf 'subjects        %s  len=%s\n' "$(code $B/subjects)" "$(wc -c </tmp/out)"
printf 'subjects/marx   %s  len=%s\n' "$(code $B/subjects/marxism)" "$(wc -c </tmp/out)"
printf 'subjects/404    %s\n' "$(code $B/subjects/nope)"

echo; echo "=== 3. 真题回顾 ==="
printf 'papers          %s\n' "$(code $B/papers)"
head -c 300 /tmp/out; echo
printf 'papers/modules  %s\n' "$(code $B/papers/modules)"
head -c 300 /tmp/out; echo
printf 'papers/2026     %s  len=%s\n' "$(code "$B/papers/2026")" "$(wc -c </tmp/out)"
printf 'papers/2026 hide %s  answer_null=%s\n' "$(code "$B/papers/2026?reveal=0")" "$(grep -o '"answer":null' /tmp/out | wc -l)"
printf 'questions       %s\n' "$(code "$B/questions?module=my&perPage=2")"
head -c 500 /tmp/out; echo
printf 'questions/1     %s\n' "$(code "$B/questions/1")"

echo; echo "=== 4. 分析/时政/预测 ==="
printf 'analysis        %s\n' "$(code $B/analysis)"
head -c 300 /tmp/out; echo
printf 'hotspots        %s\n' "$(code $B/hotspots)"
head -c 300 /tmp/out; echo
printf 'predictions     %s\n' "$(code $B/predictions)"
head -c 300 /tmp/out; echo

echo; echo "=== 5. 错题 ==="
printf 'students        %s\n' "$(code $B/mistakes/students)"
head -c 500 /tmp/out; echo
printf 'A/items         %s\n' "$(code "$B/mistakes/students/A/items")"
head -c 200 /tmp/out; echo
printf 'A/handbooks     %s\n' "$(code "$B/mistakes/students/A/handbooks")"
head -c 200 /tmp/out; echo

echo; echo "=== 6. 模拟 ==="
printf 'mocks           %s\n' "$(code $B/mocks)"
head -c 300 /tmp/out; echo
echo; echo "=== 7. 统一检索 ==="
printf 'search?q=马原     %s\n' "$(code "$B/search?q=%E9%A9%AC%E5%8E%9F")"
head -c 600 /tmp/out; echo
printf 'search empty      %s  items=%s\n' "$(code "$B/search?q=")" "$(grep -o '"items":\[\]' /tmp/out | wc -l)"
printf 'search type=bad   %s\n' "$(code "$B/search?q=x&type=nope")"
printf 'search long       %s\n' "$(code "$B/search?q=$(python3 -c 'print("a"*80)')")"

echo; echo "=== 8. 认证 ==="
TOKEN=$(curl -s -X POST "$B/auth/login" -H 'Content-Type: application/json' \
  -d '{"email":"admin@guanlan.local","password":"guanlan2027"}' \
  | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
printf 'login            token_len=%s\n' "${#TOKEN}"
printf 'me (no token)    %s\n' "$(code "$B/auth/me")"
printf 'me (token)       %s\n' "$(code -H "Authorization: Bearer $TOKEN" "$B/auth/me")"
head -c 300 /tmp/out; echo
printf 'bad password     %s\n' "$(code -X POST "$B/auth/login" -H 'Content-Type: application/json' -d '{"email":"admin@guanlan.local","password":"wrong"}')"
printf 'register dup     %s\n' "$(code -X POST "$B/auth/register" -H 'Content-Type: application/json' -d '{"email":"admin@guanlan.local","password":"guanlan2027","displayName":"dup"}')"

echo; echo "=== 9. 用户态（收藏/笔记/进度）==="
AUTH=(-H "Authorization: Bearer $TOKEN")
printf 'favorites        %s\n' "$(code "${AUTH[@]}" "$B/study/favorites")"
printf 'notes            %s\n' "$(code "${AUTH[@]}" "$B/study/notes")"
printf 'progress         %s\n' "$(code "${AUTH[@]}" "$B/study/progress")"
printf 'stats            %s\n' "$(code "${AUTH[@]}" "$B/study/stats")"
printf 'progress save    %s\n' "$(code "${AUTH[@]}" -X POST "$B/study/progress" -H 'Content-Type: application/json' -d '{"scope":"paper","ref":"2026","label":"2026 真题","status":"reading","progress":40}')"
printf 'favorite toggle  %s\n' "$(code "${AUTH[@]}" -X POST "$B/study/favorites" -H 'Content-Type: application/json' -d '{"targetType":"question","targetId":1,"title":"测试收藏","url":"/papers/2026"}')"
printf 'note create      %s\n' "$(code "${AUTH[@]}" -X POST "$B/study/notes" -H 'Content-Type: application/json' -d '{"targetType":"question","targetId":1,"content":"测试笔记"}')"
MISTAKE_ID=$(curl -s "$B/mistakes/students/A/items?perPage=1" | sed -n 's/.*"items":\[{"id":\([0-9]*\).*/\1/p')
printf 'mistake action   %s\n' "$(code "${AUTH[@]}" -X PATCH "$B/mistakes/items/$MISTAKE_ID/action" -H 'Content-Type: application/json' -d '{"action":"测试重练建议"}')"
printf 'study (no token) %s\n' "$(code "$B/study/favorites")"

echo; echo "=== 10. 后台 ==="
printf 'overview (admin) %s\n' "$(code "${AUTH[@]}" "$B/admin/overview")"
head -c 400 /tmp/out; echo
printf 'users (admin)    %s\n' "$(code "${AUTH[@]}" "$B/admin/users")"
printf 'hotspots (admin) %s\n' "$(code "${AUTH[@]}" "$B/admin/hotspots")"
printf 'analysis (admin) %s\n' "$(code "${AUTH[@]}" "$B/admin/analysis")"
printf 'papers (admin)   %s\n' "$(code "${AUTH[@]}" "$B/admin/papers?page=1&perPage=5")"
printf 'papers qs (admin) %s\n' "$(code "${AUTH[@]}" "$B/admin/papers/2026/questions?page=1&perPage=5")"
printf 'mocks (admin)    %s\n' "$(code "${AUTH[@]}" "$B/admin/mocks")"
printf 'predictions (admin) %s\n' "$(code "${AUTH[@]}" "$B/admin/predictions")"
printf 'admin (no token) %s\n' "$(code "$B/admin/overview")"
printf 'papers (no token) %s\n' "$(code "$B/admin/papers")"

# 内容状态：隐藏一条热点后，详情 404、恢复发布后可访问
HS_ID=$(curl -s "${AUTH[@]}" "$B/admin/hotspots" | sed -n 's/.*"data":{"items":\[{"id":\([0-9]*\).*/\1/p')
HS_SLUG=$(curl -s "${AUTH[@]}" "$B/admin/hotspots" | sed -n 's/.*"data":{"items":\[{"id":[0-9]*,"slug":"\([^"]*\)".*/\1/p')
printf 'hotspot hide     %s\n' "$(code "${AUTH[@]}" -X PATCH "$B/admin/hotspots/$HS_ID" -H 'Content-Type: application/json' -d '{"status":"hidden"}')"
printf 'hidden detail 404 %s (expect 404)\n' "$(code "$B/hotspots/$HS_SLUG")"
printf 'hotspot publish  %s\n' "$(code "${AUTH[@]}" -X PATCH "$B/admin/hotspots/$HS_ID" -H 'Content-Type: application/json' -d '{"status":"published"}')"
printf 'republish detail %s (expect 200)\n' "$(code "$B/hotspots/$HS_SLUG")"
printf 'bad status fallback %s (published 回退，仍 200)\n' "$(code "${AUTH[@]}" -X PATCH "$B/admin/hotspots/$HS_ID" -H 'Content-Type: application/json' -d '{"status":"draft"}')"
# B1 错题后台：考生列表、条目分页、profile 上传回读
printf 'mist students (admin) %s\n' "$(code "${AUTH[@]}" "$B/admin/mistakes/students")"
printf 'mist items (admin)   %s\n' "$(code "${AUTH[@]}" "$B/admin/mistakes/students/A/items?perPage=5")"
printf 'mist profile GET     %s\n' "$(code "${AUTH[@]}" "$B/admin/mistakes/students/A/profile")"
PROF_MD="B1 smoke: 考点结论测试行"
printf 'mist profile PUT     %s\n' "$(code "${AUTH[@]}" -X PUT "$B/admin/mistakes/students/A/profile" -H 'Content-Type: application/json' -d "{\"markdown\":\"$PROF_MD\",\"sourceFile\":\"b1-smoke.md\"}")"
printf 'mist profile reread  %s (expect 1)\n' "$(curl -s "${AUTH[@]}" "$B/admin/mistakes/students/A/profile" | grep -c 'b1-smoke.md')"
# 非管理员 403：注册临时用户后访问后台错题接口
U_TOKEN=$(curl -s -X POST "$B/auth/register" -H 'Content-Type: application/json' -d "{\"email\":\"b1-smoke-$(date +%s)@guanlan.local\",\"password\":\"guanlan2027\",\"displayName\":\"b1smoke\"}" | sed -n 's/.*"token":"\([^"]*\)\".*/\1/p')
printf 'mist students (user) %s (expect 403)\n' "$(code -H "Authorization: Bearer $U_TOKEN" "$B/admin/mistakes/students")"
printf 'mist profile PUT (user) %s (expect 403)\n' "$(code -H "Authorization: Bearer $U_TOKEN" -X PUT "$B/admin/mistakes/students/A/profile" -H 'Content-Type: application/json' -d '{"markdown":"x"}')"
# 考生端 detail 接口回读上传内容（公开考生 A）
printf 'student detail (user) %s (expect 200)\n' "$(code -H "Authorization: Bearer $U_TOKEN" "$B/mistakes/students/A/detail")"
printf 'detail carries html  %s (expect 1)\n' "$(curl -s -H "Authorization: Bearer $U_TOKEN" "$B/mistakes/students/A/detail" | grep -c 'markdown-body\|B1 smoke')"

# B3 错题条目维护：PATCH 200 / 非管理员 403 / 不存在 404 / 空字段 422
B3_ITEM_ID=$(curl -s "${AUTH[@]}" "$B/admin/mistakes/students/A/items?perPage=1" | sed -n 's/.*"items":\[{"id":\([0-9]*\).*/\1/p')
printf 'mist item PATCH    %s\n' "$(code "${AUTH[@]}" -X PATCH "$B/admin/mistakes/items/$B3_ITEM_ID" -H 'Content-Type: application/json' -d '{"action":"B3 smoke 建议","errorType":"概念混淆"}')"
printf 'mist item reread   %s (expect 1)\n' "$(curl -s "${AUTH[@]}" "$B/admin/mistakes/students/A/items?perPage=1" | grep -c 'B3 smoke 建议')"
printf 'mist item (user)   %s (expect 403)\n' "$(code -H "Authorization: Bearer $U_TOKEN" -X PATCH "$B/admin/mistakes/items/$B3_ITEM_ID" -H 'Content-Type: application/json' -d '{"action":"x"}')"
printf 'mist item 404      %s (expect 404)\n' "$(code "${AUTH[@]}" -X PATCH "$B/admin/mistakes/items/99999999" -H 'Content-Type: application/json' -d '{"action":"x"}')"
printf 'mist item empty    %s (expect 422)\n' "$(code "${AUTH[@]}" -X PATCH "$B/admin/mistakes/items/$B3_ITEM_ID" -H 'Content-Type: application/json' -d '{}')"
# B2 复习数据看板
printf 'mist review-stats  %s\n' "$(code "${AUTH[@]}" "$B/admin/mistakes/review-stats")"

# AI 连接测试：未登录 401；内网 baseUrl SSRF 拦截（ok=false 且提示内网）
printf 'ai test (no token) %s (expect 401)\n' "$(code -X POST "$B/mistakes/ai/test" -H 'Content-Type: application/json' -d '{"provider":"openai","apiKey":"x"}')"
printf 'ai test ssrf       %s (expect 1, ok=false 内网拦截)\n' "$(curl -s "${AUTH[@]}" -X POST "$B/mistakes/ai/test" -H 'Content-Type: application/json' -d '{"provider":"openai","apiKey":"x","baseUrl":"http://169.254.169.254"}' | grep -c '内网')"
printf 'ai chat-test (no token) %s (expect 401)\n' "$(code -X POST "$B/mistakes/ai/chat-test" -H 'Content-Type: application/json' -d '{"provider":"openai","apiKey":"x"}')"
printf 'ai chat-test ssrf %s (expect 1, 内网拦截)\n' "$(curl -s "${AUTH[@]}" -X POST "$B/mistakes/ai/chat-test" -H 'Content-Type: application/json' -d '{"provider":"custom","endpoint":"http://169.254.169.254"}' | grep -c '内网')"


echo; echo "=== 11. 详情页 404 ==="
printf 'analysis/404     %s\n' "$(code "$B/analysis/nope")"
printf 'hotspots/404     %s\n' "$(code "$B/hotspots/nope")"
printf 'predictions/404  %s\n' "$(code "$B/predictions/nope")"
printf 'mocks/404        %s\n' "$(code "$B/mocks/nope")"

echo; echo "=== 冒烟结束 ==="
