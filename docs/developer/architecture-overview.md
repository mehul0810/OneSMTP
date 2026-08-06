# Architecture Overview

Core modules:

- Provider adapters (per SMTP/API provider)
- Routing/failover engine
- Retry orchestration (Action Scheduler)
- Logging subsystem (custom DB tables)
- Admin settings + controls
- Bounded third-party migration services that analyze before importing and never mutate the source plugin

## Reliability Design

- Routing decisions are deterministic and testable.
- Retry jobs are asynchronous to avoid request blocking.
- Action Scheduler is the required MVP retry backend; when it is unavailable, retry scheduling fails closed and the condition remains visible through Queue Diagnostics and the diagnostic report.
- Provider switching is based on failure thresholds.
- REST routes declare argument schemas and reject unsupported provider payload fields before persistence.
- Simulation mode terminates the pipeline after capture with a distinct `simulated` status and `message_simulated` event; it never constructs a provider attempt.
- SureMail migration re-reads and fingerprints the source default connection, then delegates credential persistence to `ProviderRepository` so values are encrypted by `SecretVault` rather than copied as source ciphertext.
