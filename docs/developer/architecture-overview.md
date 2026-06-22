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
- Action Scheduler is the required MVP retry backend; when it is unavailable, retry scheduling fails closed and an admin notice makes the outage visible.
- Provider switching is based on failure thresholds.
- REST routes declare argument schemas and reject unsupported provider payload fields before persistence.
