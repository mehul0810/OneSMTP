# Retries and Resend

## Automated Retries

- Retries are managed by Action Scheduler.
- Maximum retries in MVP: 6 attempts.
- A retryable provider failure immediately tries each other healthy active provider once. Each switch is recorded as a `provider_failover` event and the attempt lineage marks the attempt trigger as `failover`.
- If the active provider pool is exhausted, the message remains in the queue and Action Scheduler applies exponential backoff for the next retry cycle.
- If Action Scheduler cannot accept a retry job, Aculect Mail fails closed: it records a retry scheduling failure event and does not queue a hidden retry.
- Failures are classified into safe retryable, terminal, authentication, quota, timeout, or policy categories where provider responses allow it.
- Terminal, authentication, and policy failures stop automatic retry scheduling because another attempt is not expected to succeed without operator action.

## Delivery Rate Limits

Aculect Mail can apply optional site-wide delivery caps from the Settings section:

- Per-minute limit
- Hourly limit
- Daily limit

Set a value to `0` to disable that limit. When a configured limit is exhausted, Aculect Mail keeps the message queued and schedules the next eligible attempt through Action Scheduler. No provider request is made while the budget is exhausted.

The deferral event records only operational metadata such as the exhausted window, configured limit, current usage count, retry delay, and run time. It does not include message bodies, recipient lists, provider secrets, credentials, tokens, or raw provider payloads.

## Background Sending

Background sending can be enabled from the Settings section. When enabled, normal WordPress mail is captured, stored, and queued through Action Scheduler for asynchronous first-attempt delivery so user-facing requests are not blocked by provider latency.

Provider test emails and manual resends continue to run synchronously so administrators receive actionable status immediately. Code paths that need synchronous behavior can mark the mail payload with `onesmtp_send_mode` set to `sync`.

Background queue events record only operational metadata such as the attempt number and scheduled run time. They do not include provider secrets, credentials, recipients, message bodies, raw headers, raw payload JSON, or provider response payloads.

## Manual Resend

Admins can manually resend failed emails and optionally choose a provider override.

The Activity section also exposes the live email queue. Administrators with resend capability can move queued or scheduled messages to the front of the queue with **Retry now**. In-progress messages cannot be duplicated, and every queue action remains represented in the message/event lineage.

## Operational Guidance

Use logs to confirm whether failures are credential, policy, timeout, quota, terminal, or provider-availability related.
If a retry is expected but never appears in Action Scheduler, inspect the latest event/log entry for a scheduler-unavailable failure before retrying manually.
If a message is delayed by configured delivery limits, confirm Action Scheduler is available and wait for the deferred attempt time shown in the queue or event context.
If Action Scheduler cannot accept a provider-budget deferral, Aculect Mail fails closed: the message is marked failed and a terminal `provider_quota_scheduler_unavailable` event records the safe operational reason rather than leaving a queued or retrying message stuck.
If background sending is enabled and mail remains queued, confirm Action Scheduler is available and processing the `onesmtp_process_background_send` action.
