# Email Logs

## What is Logged

- Message attempt status
- Provider used for each attempt
- Timestamp and error outcome details
- Retry attempt number

## Admin Views

Administrators with the OneSMTP log-view capability can open the Logs section from the OneSMTP admin screen.

The recent message list shows:

- Message ID and lineage UUID
- Delivery status
- Selected provider
- Attempt count
- Safe recipient metadata, limited to recipient count and domains
- Created and updated timestamps

The message detail view shows:

- Message status, selected provider, retry timestamp, and lineage UUID
- Attempt-level provider, trigger type, result, latency, provider message identifier, and timestamp
- Redacted and length-limited error context for failed attempts

## Privacy Boundary

The admin log views do not display full recipient addresses, message bodies, raw headers, provider secrets, API keys, tokens, or raw stored payloads. Error context is redacted and truncated before rendering.

## Storage

Logs are written to custom OneSMTP database tables for queryability and operational reporting.

## Retention

- Default: 30 days
- Extendable via filter: up to 120 days
