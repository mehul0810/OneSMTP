# Data Model

OneSMTP uses custom database tables for operational email records.

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
- Activation is repeatable and refreshes the stored `onesmtp_version` option to the current plugin version.
- Activation seeds the default log retention option only when it does not already exist, preserving administrator changes.
- Uninstall preserves operational records and settings by default. A destructive deletion path requires an explicit product decision, migration plan, and user-facing control before it can ship.
