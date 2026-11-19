#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ -f "$ROOT/config.env" ]]; then
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line#"${line%%[![:space:]]*}"}"
    [[ -z "$line" || "$line" == \#* || "$line" != *=* ]] && continue
    key="${line%%=*}"
    value="${line#*=}"
    key="$(echo "$key" | sed 's/[[:space:]]*$//')"
    value="$(echo "$value" | sed 's/^[[:space:]]*//; s/^["'\'']//; s/["'\'']$//')"
    if [[ -n "$key" && -z "${!key:-}" ]]; then
      export "$key=$value"
    fi
  done < "$ROOT/config.env"
fi

HOST="${HOST:-0.0.0.0}"
PORT="${PORT:-5400}"

echo "Starting Pixiv Animation on http://127.0.0.1:${PORT}"
exec php -S "${HOST}:${PORT}" "$ROOT/router.php"
