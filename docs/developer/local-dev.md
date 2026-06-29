# Local Development

## Recommended Setup

- WordPress local install
- Debug logging enabled
- Action Scheduler UI/inspection access
- PHP CLI for Composer scripts, including translation template generation

## Validation Flow

1. Configure at least 2 providers.
2. Trigger a test send.
3. Simulate provider failure.
4. Verify retry scheduling + provider switching.
5. Confirm Action Scheduler is loaded in admin requests; OneSMTP should not show the retry scheduler unavailable notice in a healthy environment.
6. Verify log records and retention behavior.

## Internationalization

- Confirm plugin metadata declares `Text Domain: onesmtp` and `Domain Path: /languages`.
- Regenerate the translation template before release packaging:
  - `composer i18n:make-pot`
- Review `languages/onesmtp.pot` when adding or changing user-facing strings.
