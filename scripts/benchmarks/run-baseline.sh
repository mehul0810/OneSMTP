#!/usr/bin/env bash
set -euo pipefail

PROFILE="${1:-smoke}"
OUT_DIR="${2:-./artifacts/perf}"
STAMP="$(date +%Y%m%d-%H%M%S)"
RUN_DIR="${OUT_DIR}/${PROFILE}-${STAMP}"

mkdir -p "$RUN_DIR"

echo "[onesmtp-perf] profile=$PROFILE out=$RUN_DIR"

php scripts/benchmarks/seed-fixtures.php \
  --profile="$PROFILE" \
  --out="$RUN_DIR/seed.json"

php scripts/benchmarks/simulate-queue.php \
  --profile="$PROFILE" \
  --out="$RUN_DIR/metrics.json"

php scripts/benchmarks/report-summary.php \
  --in="$RUN_DIR/metrics.json" \
  --out="$RUN_DIR/summary.md"

echo "[onesmtp-perf] completed"
echo "[onesmtp-perf] metrics: $RUN_DIR/metrics.json"
echo "[onesmtp-perf] summary: $RUN_DIR/summary.md"
