# Local Development

## Recommended Setup

- WordPress local install
- Debug logging enabled
- Action Scheduler UI/inspection access

## Validation Flow

1. Configure at least 2 providers.
2. Trigger a test send.
3. Simulate provider failure.
4. Verify retry scheduling + provider switching.
5. Verify log records and retention behavior.
