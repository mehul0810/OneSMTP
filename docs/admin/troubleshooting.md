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

## Configured Delivery Limits

If mail is accepted by OneSMTP but does not immediately leave the queue, check whether per-minute, hourly, or daily delivery limits are configured in Settings.

When a configured limit is exhausted, OneSMTP defers the message through Action Scheduler. This is expected backpressure behavior and does not indicate a provider failure.

## Retry Scheduler Unavailable Notice

OneSMTP treats Action Scheduler as the MVP retry backend. If Action Scheduler is not loaded, OneSMTP fails closed instead of pretending a retry was queued.

Admins who can manage OneSMTP see a dashboard notice when the scheduler backend is unavailable. Before packaging or approving a release build, confirm the notice is absent on a normal WordPress admin request and that retry scheduling tests pass.
