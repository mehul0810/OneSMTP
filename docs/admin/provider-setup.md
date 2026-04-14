# Provider Setup

Supported MVP providers:

- PHP mail
- Gmail
- SendGrid
- Postmark
- Brevo

## Setup Pattern

1. Add credentials/API keys for each provider.
2. Mark provider priority or rotation order.
3. Set primary and backup behavior.
4. Send provider-specific test email.

## Notes

- Keep API keys scoped and rotated regularly.
- Do not reuse high-privilege keys across environments.
