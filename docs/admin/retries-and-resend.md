# Retries and Resend

## Automated Retries

- Retries are managed by Action Scheduler.
- Maximum retries in MVP: 6 attempts.
- Provider auto-switch is triggered after repeated failure threshold.

## Manual Resend

Admins can manually resend failed emails and optionally choose a provider override.

## Operational Guidance

Use logs to confirm whether failures are credential, policy, timeout, or provider-availability related.
