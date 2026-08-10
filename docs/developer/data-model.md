# Data Model

Aculect Mail uses custom database tables for operational email records.

## Table Intent (MVP)

- Attempt table: one row per send attempt
- Message aggregate table (optional): overall lifecycle per logical email
- Provider metadata fields: provider id/name, status code, error context

## Required Fields (minimum)

- Message identifier
- Attempt number
- Provider identifier
- Status (queued/sent/failed)
- Error message/code
- Created timestamp

## Lifecycle Contract

- Activation creates or upgrades the provider, message, attempt, and event tables with `dbDelta`.
- The additive `onesmtp_provider_events` table stores only normalized Mailgun
  event categories, provider/message references, provider message IDs,
  occurrence time, a unique external-event hash, and recipient HMACs for hard
  bounces/complaints. The existing attempts table also has a composite
  provider/message-ID lookup index for correlation.
- Activation is repeatable and refreshes the stored `onesmtp_version` option to the current plugin version.
- Activation seeds the default log retention option only when it does not already exist, preserving administrator changes.
- Uninstall preserves operational records and settings by default. A destructive deletion path requires an explicit product decision, migration plan, and user-facing control before it can ship.
- Provider-event rows use the same site-local 30-day default, bounded 1–120 day
  retention policy, and daily batch pruner as other operational records. A
  disabled gate stops new writes; existing rows age out normally.
- The additive `onesmtp_suppressions` table stores only a site-context-bound
  recipient HMAC, bounded domain, reason, provider identifier, timestamps,
  expiry, and count. A separate `onesmtp_suppression_derivations` claim table
  keeps normalized hard-bounce/complaint derivation pending until suppression
  persistence succeeds, with a cryptographically random per-claim token
  fencing stale workers after reclaim. Provider retries can complete a failed
  write without inflating occurrence counts. It is populated only from normalized
  authenticated events when both Pro gates and the default-off site toggle are
  enabled; plaintext recipients, provider message IDs, and payloads never
  cross the suppression repository boundary.
