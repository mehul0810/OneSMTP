# Performance Baseline and Benchmarking

This document defines the first performance baseline for OneSMTP MVP and the lightweight harness used to track regressions.

## Scope

Focus areas aligned to the queue/retry model:

- Queue pickup efficiency (Action Scheduler worker path)
- Retry scheduling decisions (max 6 attempts, failover transitions)
- Attempt write throughput and duplicate prevention
- Provider selection overhead under healthy and degraded states
- Admin log read path for recent rows (30-day retention window)

## Baseline Targets (MVP)

These are initial engineering targets before provider network latency:

- Queue dispatch decision path: p95 <= 50ms
- Retry scheduler decision path: p95 <= 35ms
- Attempt insert path: p95 <= 20ms
- Duplicate-attempt conflicts: 0 successful duplicates for same `(message_id, attempt_no)`
- Admin log list query (page size 50, last 30 days): p95 <= 250ms at 100k attempt rows

## Test Profiles

Profiles are intentionally small and practical for repeatability.

1. `smoke`
- 1,000 synthetic messages
- 5 providers configured
- 5% transient failures
- Goal: quick signal in local/CI

2. `mvp-baseline`
- 10,000 synthetic messages
- 5 providers configured
- 15% transient failures, 2% hard failures
- Goal: primary baseline for MVP stability

3. `stress-lite`
- 25,000 synthetic messages
- 5 providers configured
- 25% transient failures, 5% hard failures
- Goal: identify scheduler/query hotspots

## Metrics To Capture

For each profile:

- Total processed messages
- Success/failure counts
- Retry count distribution
- Provider switch count after two consecutive failures
- Duplicate prevention trigger count
- p50/p95/p99 latency for:
  - dispatch decision
  - retry scheduling
  - attempt insert
- Peak memory usage and worker runtime

## Execution Workflow

1. Seed synthetic queue/retry data
2. Run benchmark simulation profile
3. Capture raw JSON metrics
4. Build markdown summary from latest run
5. Compare against target thresholds

Use scripts in `scripts/benchmarks`:

- `run-baseline.sh`: profile runner wrapper
- `seed-fixtures.php`: synthetic fixture seeding stub
- `simulate-queue.php`: queue/retry simulation stub
- `report-summary.php`: summary renderer

## Acceptance Criteria For P0 Harness

- Scripts run without external services
- Profiles selectable by CLI arg
- Output includes machine-readable JSON and markdown summary
- Non-zero exit when baseline thresholds are violated (guardrail for CI use)

## Notes

- Current harness is skeleton-only by design and does not execute real SMTP calls.
- Network/provider latency should be benchmarked separately as an integration layer once adapters are wired.
