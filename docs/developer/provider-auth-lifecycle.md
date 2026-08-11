# Provider authentication lifecycle (0.4.0 candidate)

This document describes the unreleased, Pro-gated candidate implementation for
issues #50 and #51. It is site-local and customer-owned: the administrator
supplies the Gmail or Zoho Mail client registration and registers the exact
HTTPS callback shown in the provider drawer. No hosted broker, shared Aculect
secret, telemetry, or cross-site token transfer is used.

## Bounded contract

`OneSMTP\Providers\Auth\ProviderAuthState` is limited to `unsupported`, `static`, `disconnected`, `configured_unverified`, `connected`, `refresh_failed`, `reauth_required`, `revoked`, and `unknown`. Complete stored credentials without a verified refresh are `configured_unverified`; production UI must never present that state as connected.

Capabilities are explicit context, not inferred from a state label. Status objects default to `can_reconnect=false` and `can_revoke=false`. The evaluator may expose reconnect as a future credential-replacement affordance for configured Zoho evidence, but it does not imply callback availability. Revoke remains false for all ordinary outputs. It is available only when a future-approved context supplies verified, token-bearing revocation evidence alongside a verified connected result.

`ProviderAuthConfiguration` retains only presence flags for refresh credentials. `ProviderAuthRefreshResult` maps the existing result contract to bounded outcomes without retaining error text, tokens, client values, account values, or email addresses.

Unknown providers and unknown refresh outcomes fail closed as `unknown`. Gmail
and Zoho refresh success, network failure, invalid-credential or
`invalid_grant`-style failure, and revoked outcomes map to stable redacted
states. Complete stored credentials without a verified exchange remain
`configured_unverified`; only a successful exchange/refresh may produce
`connected`.

## Runtime contract

`provider_auth_lifecycle` requires both the Pro entitlement and its rollout
flag. When disabled, existing core provider setup and Zoho manual refresh
credentials remain untouched and lifecycle endpoints are inert. Enabled admin
mutations use the WordPress REST nonce and `manage_onesmtp` capability; the
callback additionally requires the logged-in user and a one-time state bound to
provider ID/type, user, return target, and a two-minute TTL.

Google requests only `https://www.googleapis.com/auth/gmail.send`,
`access_type=offline`, and does not claim PKCE support. Zoho uses the selected
regional accounts host, `ZohoMail.messages.CREATE`, `access_type=offline`, and
S256 PKCE. Token exchange and refresh are HTTPS-only, bounded, and redacted.
Client and refresh credentials remain in the existing encrypted provider
configuration; the callback access token is discarded rather than left in that
configuration, and refresh-on-demand access tokens live only in an encrypted
transient cache with a five-minute expiry skew. Existing manual Zoho access
tokens remain compatible. Disconnect attempts the provider revoke endpoint,
then removes local access/refresh credentials and deactivates the provider;
remote-revoke failure returns bounded retry guidance. If a credential rewrite
fails, the provider is deactivated where possible and an independent durable
disconnect block prevents delivery until cleanup succeeds.

Gmail sends through `users.messages.send` with a narrow Bearer header and a
base64url MIME message, including bounded attachments. Zoho keeps its existing
regional send and refresh path while sharing the lifecycle status contract.

Provider registration follows Google's [web-server OAuth guidance](https://developers.google.com/workspace/gmail/api/auth/web-server),
the [Gmail send API](https://developers.google.com/gmail/api/reference/rest/v1/users.messages/send),
and Zoho's [server-based OAuth guide](https://www.zoho.com/mail/help/api/using-oauth-2.html).
Google may require customer app verification before production use; the site
administrator owns that provider-console setup.

The implementation adds no new database table or public unauthenticated API.
One-time callback state remains in a bounded transient and is fenced by a
unique, short-lived WordPress option claim so concurrent callbacks cannot both
consume it. A disconnect cleanup failure leaves an independent option marker
that blocks the provider from the active pool until cleanup succeeds.
The local REST contracts are:

- `POST /wp-json/onesmtp/v1/providers/{id}/oauth/start`
- `GET /wp-json/onesmtp/v1/providers/{id}/oauth/callback`
- `GET /wp-json/onesmtp/v1/providers/{id}/oauth/status`
- `POST /wp-json/onesmtp/v1/providers/{id}/oauth/disconnect`

No code, state, token, client secret, raw provider response, or diagnostic text
is logged, audited, exported, or returned to the admin UI.
