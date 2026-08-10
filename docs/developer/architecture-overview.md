# Architecture Overview

Core modules:

- Provider adapters (per SMTP/API provider)
- Routing/failover engine
- Retry orchestration (Action Scheduler)
- Logging subsystem (custom DB tables)
- Admin settings + controls
- Bounded third-party migration services that analyze before importing and never mutate the source plugin
- Default-deny Pro capability gates that require both entitlement and an internal rollout flag
- Advanced alert escalation extends the existing email/webhook channels with bounded destination lists and allowlisted terminal-failure context; raw mail payloads never cross the alert or audit boundary
- Pro provider sending budgets extend the existing provider configuration with bounded minute/hour/day attempt windows. A shared, owner-fenced database lease coordinates quota decisions and in-flight reservations immediately before dispatch, returning the earliest-capacity deferral when the pool is exhausted; only production attempt rows are counted and quota metadata is non-secret. Scheduler enqueue failure fails closed to a terminal message state rather than leaving a hidden queued/retrying message.

## Reliability Design

- Routing decisions are deterministic and testable.
- Retry jobs are asynchronous to avoid request blocking.
- Action Scheduler is the required MVP retry backend; when it is unavailable, retry scheduling fails closed and the condition remains visible through Queue Diagnostics and the diagnostic report.
- Provider switching is based on failure thresholds.
- REST routes declare argument schemas and reject unsupported provider payload fields before persistence.
- Simulation mode terminates the pipeline after capture with a distinct `simulated` status and `message_simulated` event; it never constructs a provider attempt.
- SureMail migration re-reads and fingerprints the source default connection, then delegates credential persistence to `ProviderRepository` so values are encrypted by `SecretVault` rather than copied as source ciphertext.
- Optional Pro modules use the central `FeatureGate`. Free installations deny every Pro feature, an entitlement alone does not enable unfinished behavior, and unknown feature IDs fail closed.
- Advanced alerts require both the `advanced_alerts` entitlement and rollout flag. Escalation is deterministic: it fires at the configured terminal-failure attempt threshold, and each destination is revalidated before dispatch.
- Conditional routing uses bounded, allowlisted rule definitions and a transient payload-derived context. Sender, recipient, subject, content, and source attribution matching never persists or logs the message context; unmatched or unavailable rules fall back to core provider selection.
- Provider adapters retain the additive `ProviderAdapterInterface`; the registry builds a descriptor catalog that binds each slug to `ProviderTypes` metadata, capabilities, bounded credentials, bundled icon metadata, and normalized `SendResult` mapping. Malformed, duplicate, incomplete, or unregistered catalog declarations fail closed. See `docs/developer/provider-adapters.md` for the authoring and release contract.
- Pro routing simulation reuses the same normalizer, transient context builder, and evaluator as production selection while returning decision metadata only. It never enters delivery, queue, retry, message, attempt, event, audit, or rule persistence paths.
