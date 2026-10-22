#!/usr/bin/env bash
# Artifact-only updates. Run prepare first; see docs/dev29/release-runbook.md.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec python3 "$SCRIPT_DIR/release.py" "$@"
