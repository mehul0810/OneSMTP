# CI Performance Smoke

OneSMTP runs a lightweight performance smoke workflow in GitHub Actions.

## Workflow

- File: `.github/workflows/performance-smoke.yml`
- Trigger: pull requests, pushes to `main`, and manual dispatch
- Purpose: detect regressions in queue/retry and large log-table decision paths before merge

## What Runs

The job executes:

```bash
./scripts/benchmarks/run-baseline.sh smoke
```

This command:

1. Seeds synthetic profile data metadata
2. Runs queue/retry simulation stubs
3. Runs large synthetic log-table list/filter/export/detail simulation and schema index checks
4. Produces JSON metrics and markdown summary under `artifacts/perf/`
5. Returns non-zero exit if thresholds are exceeded or expected indexes are missing

## Artifacts

The workflow uploads `artifacts/perf/` as `onesmtp-performance-smoke` for each run.

## Current Limits

- Queue/retry metrics still use deterministic skeleton values.
- Log-table metrics use deterministic fake message and attempt tables with no real recipients, bodies, headers, secrets, or production logs.
- Replace static queue/retry simulation values with runtime instrumentation as dispatch/retry code matures.
