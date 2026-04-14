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
