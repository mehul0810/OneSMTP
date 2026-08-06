# Email Logs

## What is Logged

- Message attempt status
- Provider used for each attempt
- Timestamp and error outcome details
- Retry attempt number
- Simulated status when staging simulation mode captures a message without delivery

## Admin Views

Administrators with the Aculect Mail log-view capability can open the Logs section from the Aculect Mail admin screen.

The Activity section starts with a queue summary and a privacy-safe queue table. Queued, scheduled, and in-progress messages can be reviewed without exposing message content. Administrators with resend capability can use **Retry now** for queued or scheduled entries; in-progress work is left untouched to prevent duplicate delivery.

The recent message list shows:

- Message ID and lineage UUID
- Delivery status
- Selected provider
- Number of provider switchovers
- Safe source attribution when available, limited to plugin, theme, WordPress core, or unknown-source labels
- Attempt count
- Attachment metadata summary when attachment logging is explicitly enabled
- Safe recipient metadata, limited to recipient count and domains
- Created and updated timestamps

The list can be filtered by delivery status, selected provider, created date range, lineage UUID, log ID, and exact 64-character recipient hash. Recipient filtering uses the existing stored hash only; Aculect Mail does not add or expose plaintext recipient search values.

Pagination is available on the log list so larger datasets can be reviewed without loading every row into the admin screen.

The message detail view shows:

- Message status, selected provider, retry timestamp, and lineage UUID
- Number of provider switches and the complete provider attempt sequence
- Safe source attribution for the WordPress origin when Aculect Mail can detect or normalize it
- Attempt-level provider, trigger type, result, latency, provider message identifier, and timestamp
- Redacted and length-limited error context for failed attempts
- Safe failure category when a provider error can be classified without exposing provider internals or secrets
- Attachment metadata when attachment logging is explicitly enabled, limited to count, safe filename, extension, and known file size

## Attachment Logging

Attachment logging is off by default. When it is off, Aculect Mail removes raw attachment references from stored log payloads.

Administrators with the Aculect Mail settings capability can enable privacy-safe attachment metadata from Settings. When enabled, Aculect Mail stores metadata only:

- Attachment count
- Safe filename
- Extension
- File size when available

Aculect Mail does not copy attachment file contents into logs and does not display raw filesystem paths, private temporary paths, or stored payload JSON in list, detail, or export output.

Attachment metadata is retained only as part of the parent email log row. It is deleted when the parent log is removed by the configured log retention policy.

Messages with file attachments may not preserve those attachments for background retries or manual resend unless the source can provide the files again.

## CSV Export

Administrators with the Aculect Mail log-view capability can export the current filtered log list to CSV from the Logs section. Export links are nonce protected and limited to safe log summary fields:

- Message ID and lineage UUID
- Delivery status
- Selected provider label
- Attempt count and maximum attempts
- Attachment metadata summary when logging is enabled
- Safe recipient summary
- Next retry, created, and updated timestamps

## Privacy Boundary

The admin log views and CSV export do not display full recipient addresses, message bodies, raw headers, provider secrets, API keys, tokens, unsafe payload JSON, raw stored payloads, raw attachment paths, filesystem paths, or stack details. Error context, source labels, and attachment metadata are sanitized and truncated before rendering.

## Storage

Logs are written to custom Aculect Mail database tables for queryability and operational reporting.

## Simulation Mode

Simulation mode is an explicitly labelled Advanced setting intended for staging
and development. When enabled, Aculect Mail captures the normal privacy-safe log
record, marks it **Simulated**, returns success to the WordPress caller, and never
selects or contacts a provider. Simulated messages are not counted as delivered
and do not enter the retry queue.

## Retention

- Default: 30 days
- Extendable via filter: up to 120 days
