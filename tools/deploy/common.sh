#!/usr/bin/env bash
# 观澜生产部署共享工具。供 deploy.sh / healthcheck.sh 通过 source 引入，不可直接执行。

set -euo pipefail

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
  echo "common.sh 只能被 source 使用，不能直接执行" >&2
  exit 1
fi

# 仓库根目录：基于本文件自身位置解析，不依赖当前工作目录。
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

# 当前使用的生产环境变量文件路径（由 resolve_env_file 填充）。
ENV_FILE=""

# 解析可选的环境变量文件路径；未传参时默认使用仓库根目录下的 .env.production。
resolve_env_file() {
  local env_arg="${1:-}"
  if [[ -n "$env_arg" ]]; then
    ENV_FILE="$env_arg"
  else
    ENV_FILE="$REPO_ROOT/.env.production"
  fi
}

# 统一的生产 Compose 包装：始终携带环境文件与生产编排文件。
compose() {
  docker compose --env-file "$ENV_FILE" -f "$REPO_ROOT/compose.production.yml" "$@"
}