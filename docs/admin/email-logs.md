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

The list can be filtered by delivery status, selected provider, created date range, lineage UUID, log ID, and exact 64-character recipient hash. Recipient filtering uses the existing stored hash only; OneSMTP does not add or expose plaintext recipient search values.

Pagination is available on the log list so larger datasets can be reviewed without loading every row into the admin screen.

The message detail view shows:

- Message status, selected provider, retry timestamp, and lineage UUID
- Attempt-level provider, trigger type, result, latency, provider message identifier, and timestamp
- Redacted and length-limited error context for failed attempts
- Safe failure category when a provider error can be classified without exposing provider internals or secrets

## CSV Export

Administrators with the OneSMTP log-view capability can export the current filtered log list to CSV from the Logs section. Export links are nonce protected and limited to safe log summary fields:

- Message ID and lineage UUID
- Delivery status
- Selected provider label
- Attempt count and maximum attempts
- Safe recipient summary
- Next retry, created, and updated timestamps

## Privacy Boundary

The admin log views and CSV export do not display full recipient addresses, message bodies, raw headers, provider secrets, API keys, tokens, unsafe payload JSON, or raw stored payloads. Error context is redacted and truncated before rendering.

## Storage

Logs are written to custom OneSMTP database tables for queryability and operational reporting.

## Retention

- Default: 30 days
- Extendable via filter: up to 120 days
