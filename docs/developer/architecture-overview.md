# Architecture Overview

Core modules:

- Provider adapters (per SMTP/API provider)
- Routing/failover engine
- Retry orchestration (Action Scheduler)
- Logging subsystem (custom DB tables)
- Admin settings + controls

## Reliability Design

- Routing decisions are deterministic and testable.
- Retry jobs are asynchronous to avoid request blocking.
- Provider switching is based on failure thresholds.
