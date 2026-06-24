# Retries and Resend

## Automated Retries

- Retries are managed by Action Scheduler.
- Maximum retries in MVP: 6 attempts.
- Provider auto-switch is triggered after repeated failure threshold.
- If Action Scheduler cannot accept a retry job, OneSMTP fails closed: it records a retry scheduling failure event and does not queue a hidden retry.
- Failures are classified into safe retryable, terminal, authentication, quota, timeout, or policy categories where provider responses allow it.
- Terminal, authentication, and policy failures stop automatic retry scheduling because another attempt is not expected to succeed without operator action.

## Manual Resend

Admins can manually resend failed emails and optionally choose a provider override.

## Operational Guidance

Use logs to confirm whether failures are credential, policy, timeout, quota, terminal, or provider-availability related.
If a retry is expected but never appears in Action Scheduler, inspect the latest event/log entry for a scheduler-unavailable failure before retrying manually.
