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
- If OneSMTP reports that provider credential recovery is required, re-enter the affected provider credentials from the Providers screen and save the provider.
- Use the DNS authentication readiness panel to review sender-domain SPF, DKIM, and DMARC guidance. Checks are based on TXT records visible to the WordPress server and are marked inconclusive when DNS lookup support or a DKIM selector is unavailable.
