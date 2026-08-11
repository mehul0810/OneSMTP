# Provider Setup

## Supported Providers

Aculect Mail includes adapters for the major transactional providers below:

- Amazon SES (SMTP credentials)
- Brevo
- Elastic Email
- Gmail (Gmail API with customer-owned OAuth; generic SMTP remains available separately)
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
until the earliest next capacity. Coordination uses a small internal database
lease table with owner tokens; it does not require an external object cache,
and expired leases are pruned in bounded batches without allowing an old worker
to release a newer lease. No message body, recipient, credential, or raw
provider context is written to quota or audit events.

Messages with attachments are not deferred through this quota path because the
stored retry payload intentionally removes raw attachment references. If every
eligible provider is exhausted for an attachment-bearing message, delivery
fails closed with a safe terminal event and no retry job is enqueued; this
prevents a later worker from sending the message without its files.

## Provider-specific credentials

- Mailgun requires a private API key, sending domain, and US/EU API region.
- Mailjet requires an API key and secret key.
- SparkPost requires an API key and US/EU API region.
- ZeptoMail requires the Agent-specific send mail token.
- SMTP2GO requires an API key from Sending > API Keys and supports the global, US, EU, and AU API regions.
- Elastic Email requires an API key with SendHttp access.
- Resend, MailerSend, Mailchimp Transactional, SendGrid, Brevo, and Postmark require their respective API key or token.
- Amazon SES requires SES SMTP credentials for the selected AWS Region, not regular AWS access keys.
- Zoho Mail requires an account ID and customer-owned OAuth client registration. The candidate requests only `ZohoMail.messages.CREATE`; existing manual refresh credentials remain compatible.
- Emailit requires an API v2 key and verified sender domain.
- Netcore requires an Email API key and the matching US or EU API region.

## Gmail and Zoho OAuth lifecycle (Pro candidate)

When the `provider_auth_lifecycle` entitlement and rollout flag are enabled,
save the customer-owned client ID and client secret as an inactive provider,
then select **Connect with Google** or **Connect with Zoho**. Register the
exact HTTPS callback URL shown in the drawer in the provider's app console.
Google requests only `https://www.googleapis.com/auth/gmail.send`, uses
server-side offline authorization, and deliberately does not claim PKCE support.
Zoho uses the selected regional accounts host, only
`ZohoMail.messages.CREATE`, `access_type=offline`, and S256 PKCE. The callback
requires the logged-in administrator, a one-time two-minute state, the exact
provider record/type, and a safe same-site return target.

The drawer reports `configured_unverified` until a token exchange or refresh is
verified; it never labels stored fields as connected. After verification, send a
test email and activate the provider. Callback access tokens are discarded from
provider configuration and kept only in the encrypted, bounded refresh cache;
existing manual Zoho access tokens remain compatible. Disconnect attempts the
provider revoke endpoint and removes local access/refresh credentials while
deactivating the connection. If credential cleanup fails, a durable disconnect
block prevents local delivery until cleanup succeeds. Tokens, authorization
codes, state, client secrets, and provider diagnostics are not logged, audited,
exported, or shown in the UI.

Use the provider's official registration references when creating the
customer-owned app: [Google web-server OAuth](https://developers.google.com/workspace/gmail/api/auth/web-server),
[Gmail send API](https://developers.google.com/gmail/api/reference/rest/v1/users.messages/send),
and [Zoho Mail OAuth](https://www.zoho.com/mail/help/api/using-oauth-2.html).
Google app verification and regional Zoho account registration remain
customer-operated requirements.

## Mailgun delivery webhooks (Pro candidate)

The 0.4.0 candidate can accept Mailgun delivery webhooks at:

`https://example.com/wp-json/onesmtp/v1/webhooks/mailgun`

Replace `example.com` with the site hostname. Configure the Mailgun webhook
with **POST** and `application/json`, and use HTTPS end to end. In the Mailgun
provider connection, enter the account's **Webhook Signing Key**. It is stored
through Aculect Mail's encrypted SecretVault and is never returned by the
provider API or rendered back into the admin UI.

The endpoint is public at the WordPress login layer because Mailgun cannot send
an authenticated WordPress session. It still fails closed unless the active
Mailgun connection exists, the Pro `provider_events` entitlement and rollout
flag are enabled, the site has a real WordPress salt, and Mailgun's timestamp,
token, and HMAC-SHA256 signature verify. Requests must be HTTPS, JSON, and no
larger than 64 KiB; timestamps more than five minutes from the site clock are
rejected. WordPress REST/PHP can buffer a request before the callback, so
enforce the 64 KiB cap at the reverse proxy or PHP/server layer as well as in
the plugin. Mailgun's timestamp+token is HMAC-verified; every verified token is
atomically claimed in a separate hashed replay store, so an exact retry is
acknowledged while a reused token with changed normalized data is rejected. A
new token for an already-seen event is also burned and acknowledged without a
second event row.

Only `delivered`, `hard_bounce`, `soft_bounce`, `complaint`, `deferred`, and
`unknown` are retained as normalized categories. Mailgun `accepted` means
queued and is deliberately recorded as `unknown`, never as delivered. A
`temporary_fail` is deferred unless Mailgun explicitly supplies temporary
bounce severity; `permanent_fail` is hard only when Mailgun explicitly supplies
permanent severity. Bounce and complaint records keep only an individual
recipient HMAC.
The opaque provider `message-id` is retained only as a correlation reference;
other provider metadata, raw payloads, plaintext recipients, headers, IPs, user
agents, diagnostics, and signing tokens are discarded after validation.
Subaccount payloads may include Mailgun's `parent-signature`; it is verified
with the configured primary Webhook Signing Key. Suppression enforcement
is separately gated by `bounce_suppression` and remains default-off.

See Mailgun's [webhook security guidance](https://documentation.mailgun.com/docs/mailgun/user-manual/webhooks/securing-webhooks)
and [webhook payload reference](https://documentation.mailgun.com/docs/mailgun/user-manual/webhooks/webhook-payloads)
for the provider-side configuration and signed payload contract.

Browser proof for the gated provider setup surface and password-protected
signing-key treatment is captured at [desktop width](screenshots/issue-63/provider-webhook-desktop.png)
and [390px narrow width](screenshots/issue-63/provider-webhook-mobile.png); the
fixture contains no live credential.

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
