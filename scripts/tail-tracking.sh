#!/usr/bin/env bash
# Live WhatsApp + pre-screening tracking logs on VPS.
# Usage: ./scripts/tail-tracking.sh [whatsapp|prescreening|all]
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
DAY="$(date +%Y-%m-%d)"
MODE="${1:-all}"

tail_one() {
  local name="$1"
  local file="storage/logs/${name}-${DAY}.log"
  if [[ ! -f "$file" ]]; then
    file="storage/logs/${name}.log"
  fi
  if [[ ! -f "$file" ]]; then
    echo "No log yet: $file (send a WhatsApp message first)" >&2
    return 1
  fi
  echo "=== $file ==="
  tail -f "$file"
}

case "$MODE" in
  whatsapp) tail_one whatsapp ;;
  prescreening) tail_one prescreening ;;
  all)
    echo "Tailing whatsapp + prescreening (Ctrl+C to stop)"
    tail -f "storage/logs/whatsapp-${DAY}.log" "storage/logs/prescreening-${DAY}.log" 2>/dev/null \
      || tail -f storage/logs/whatsapp.log storage/logs/prescreening.log 2>/dev/null \
      || { echo "No tracking logs found under storage/logs/"; exit 1; }
    ;;
  *) echo "Usage: $0 [whatsapp|prescreening|all]"; exit 1 ;;
esac
