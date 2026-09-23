#!/bin/bash
# 本机（无 Docker / 无 PHP）可跑的离线验证。
# 容器内验证请用 lint.sh / smoke.sh。
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

fail=0
step() {
  echo
  echo "== $1"
  shift
  if "$@"; then
    echo "   OK"
  else
    echo "   FAILED (exit $?)"
    fail=1
  fi
}

echo "观澜｜考研政治知识库 离线验证"
echo "工作目录: $(pwd)"

step "Python 语法检查（ingest + tools）" python -m compileall -q tools
step "PHP 结构检查（98 文件 / PSR-4 / 模型列 / 路由）" python tools/phpcheck.py
step "PHP 检查器反向自测（注入 5 类错误必须被抓到）" python tools/phpcheck_selftest.py
step "数据集重新生成" python tools/ingest/build_all.py

echo
echo "== 前端测试（node --test）"
if (cd apps/web && npm test); then echo "   OK"; else echo "   FAILED"; fail=1; fi

echo
if [ "$fail" -eq 0 ]; then
  echo "全部离线验证通过。容器验证请另跑 docs 中的 compose 流程。"
else
  echo "存在失败项。"
fi
exit "$fail"