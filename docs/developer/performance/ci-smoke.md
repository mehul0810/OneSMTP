# CI Performance Smoke

OneSMTP runs a lightweight performance smoke workflow in GitHub Actions.

## Workflow

- File: `.github/workflows/performance-smoke.yml`
- Trigger: pull requests, pushes to `main`, and manual dispatch
- Purpose: detect regressions in queue/retry decision paths before merge

## What Runs

The job executes:

```bash
./scripts/benchmarks/run-baseline.sh smoke
```

This command:

1. Seeds synthetic profile data metadata
2. Runs queue/retry simulation stubs
3. Produces JSON metrics and markdown summary under `artifacts/perf/`
4. Returns non-zero exit if thresholds are exceeded

## Artifacts

The workflow uploads `artifacts/perf/` as `onesmtp-performance-smoke` for each run.

## Current Limits

- Current harness uses deterministic skeleton metrics.
- Replace static simulation values with runtime instrumentation as dispatch/retry code matures.
