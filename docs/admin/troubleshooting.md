# Troubleshooting

## Common Failure Types

- Authentication/API key errors
- Sender/domain policy failures (SPF/DKIM/DMARC)
- Provider rate limits
- Configured site delivery limits
- Timeout or temporary provider outage

## First Checks

1. Validate provider credentials.
2. Send a test email from settings.
3. Inspect latest attempt in Email Logs.
4. Confirm retries are scheduled in Action Scheduler.
5. If no retry job exists, check for a retry scheduling failure event before assuming the message will retry automatically.

## Background Sending

If background sending is enabled, normal mail returns after the message is queued and provider delivery happens in Action Scheduler. Provider test emails and manual resends still run synchronously so administrators can see immediate success or failure details.

If a normal message stays queued, confirm Action Scheduler is loaded, WP-Cron or the Action Scheduler runner is processing jobs, and the `onesmtp_process_background_send` action is not overdue.

## Configured Delivery Limits

If mail is accepted by Aculect Mail but does not immediately leave the queue, check whether per-minute, hourly, or daily delivery limits are configured in Settings.

When a configured limit is exhausted, Aculect Mail defers the message through Action Scheduler. This is expected backpressure behavior and does not indicate a provider failure.

## Retry Scheduler Unavailable

Aculect Mail treats Action Scheduler as the MVP retry backend. If Action Scheduler is not loaded, Aculect Mail fails closed instead of pretending a retry was queued.

The admin surface does not interrupt page navigation with a global scheduler notice. Use Queue Diagnostics or the diagnostic report to confirm scheduler availability, and check the retry scheduling failure event before assuming a message will retry automatically.

## Failure Alerts

Failure alerts can be enabled from the Aculect Mail settings screen for terminal delivery failures. Admin email alerts require at least one recipient, and webhook alerts require an HTTPS URL.

Alert payloads are limited to operational metadata: event ID, timestamp, message ID/UUID, safe hashes, provider summary, failure reason, and failure category. Aculect Mail does not send raw recipients, message bodies, raw headers, stored payload JSON, provider credentials, tokens, or provider configuration in alert payloads.

## Diagnostic Report

Administrators with Aculect Mail management access can download a privacy-safe diagnostic report from the Diagnostics section. The report includes environment and plugin metadata, provider summaries, queue and scheduler state, recent failure category counts, and redaction metadata.

The diagnostic report excludes raw recipients, message subjects, message bodies, raw headers, stored payload JSON, webhook URLs, provider error messages, provider message IDs, tokens, credentials, secrets, and provider configuration values.

Repeated matching terminal failures are throttled for the configured alert window to prevent notification floods.
