# Provider Setup

## Supported Providers

Aculect Mail includes adapters for the major transactional providers below:

- Amazon SES (SMTP credentials)
- Brevo
- Elastic Email
- Gmail (SMTP)
- Mailchimp Transactional (Mandrill)
- MailerSend
- Mailgun
- Mailjet
- PHP mail
- Postmark
- Resend
- SendGrid
- SMTP2GO
- SparkPost
- ZeptoMail
- Zoho Mail
- Emailit
- Netcore
- Generic SMTP (any compatible SMTP host)

The catalog focuses on providers with documented transactional delivery APIs or
SMTP relays. It is not a claim that every regional provider has a dedicated
adapter; use Generic SMTP for providers without a first-party adapter.

## Setup Pattern

1. Add credentials/API keys for each provider.
2. Mark provider priority or rotation order.
3. Set primary and backup behavior.
4. Save the connection and send a provider-specific test email from the same expanded provider row.

## Managing configured connections

Each provider remains in a single catalog row after it is connected. The row
shows its connection name, priority, weight, circuit health, and active status.
Select **Manage** to update the configured connection in place. Existing
credentials are never displayed; leave a credential field blank while editing
to retain its stored value.

Aculect Mail intentionally supports one connection per provider in this flow.
It does not offer an “add another connection” action inside a configured row.

## Provider sending budgets (Pro)

When the Pro **Provider sending budgets** capability is enabled, each provider
connection can have independent minute, hour, and day attempt limits. Enter
`0` to disable a window; values are bounded and safely clamped at 1,000,000.
These are non-secret provider settings stored with the existing connection
configuration. Free/core installations keep the fields disabled and preserve
any existing values without enforcing them.

Only recorded production delivery attempts count: initial, retry, failover,
background, and manual-resend sends are included, while provider-test traffic
is excluded. A quota decision is checked immediately before dispatch. An
exhausted provider is skipped when another eligible provider exists. If every
eligible provider is exhausted, the message remains queued and is deferred
until the earliest next capacity; no message body, recipient, credential, or
raw provider context is written to quota or audit events.

## Provider-specific credentials

- Mailgun requires a private API key, sending domain, and US/EU API region.
- Mailjet requires an API key and secret key.
- SparkPost requires an API key and US/EU API region.
- ZeptoMail requires the Agent-specific send mail token.
- SMTP2GO requires an API key from Sending > API Keys and supports the global, US, EU, and AU API regions.
- Elastic Email requires an API key with SendHttp access.
- Resend, MailerSend, Mailchimp Transactional, SendGrid, Brevo, and Postmark require their respective API key or token.
- Amazon SES requires SES SMTP credentials for the selected AWS Region, not regular AWS access keys.
- Zoho Mail requires an account ID and OAuth access token with `ZohoMail.messages.CREATE` or `ZohoMail.messages.ALL` access.
- Emailit requires an API v2 key and verified sender domain.
- Netcore requires an Email API key and the matching US or EU API region.

## Importing from SureMail

When SureMail is installed, the Providers screen shows a quiet compatibility
card. Select **Analyze SureMail setup** before importing. Analysis exposes only
provider type, display name, import eligibility, and skipped-connection counts.

The opt-in import has strict boundaries:

- Only SureMail's declared default connection is eligible.
- Other connections and all SureMail email logs are skipped.
- Aculect Mail never deactivates SureMail or changes its settings.
- SureMail credential values are decoded in memory and saved through Aculect Mail's AES-256-GCM vault.
- The imported connection starts inactive and must be reviewed and tested.
- Import is blocked when that provider type is already configured in Aculect Mail.

Only one plugin should own live WordPress mail delivery. After validating the
imported connection, explicitly choose which plugin will remain responsible for
production delivery.

## Notes

- Keep API keys scoped and rotated regularly.
- Do not reuse high-privilege keys across environments.
- If Aculect Mail reports that provider credential recovery is required, re-enter the affected provider credentials from the Providers screen and save the provider.
- Use the DNS authentication readiness panel to review sender-domain SPF, DKIM, and DMARC guidance. Checks are based on TXT records visible to the WordPress server and are marked inconclusive when DNS lookup support or a DKIM selector is unavailable.
