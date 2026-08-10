# Provider authentication lifecycle foundation

This document describes an internal candidate contract only. It is partial work toward issue #51 and is not a shipped provider-auth feature.

The foundation provides typed, redacted lifecycle states and a pure evaluator for evidence already available in provider configuration and refresh results. It does not perform a refresh, revoke a token, persist credentials, or call a provider.

## Bounded contract

`OneSMTP\Providers\Auth\ProviderAuthState` is limited to `unsupported`, `static`, `disconnected`, `connected`, `refresh_failed`, `reauth_required`, `revoked`, and `unknown`. `ProviderAuthStatus` exposes only the state and side-effect-free `can_reconnect` / `can_revoke` capability flags.

`ProviderAuthConfiguration` retains only presence flags for refresh credentials. `ProviderAuthRefreshResult` maps the existing result contract to bounded outcomes without retaining error text, tokens, client values, account values, or email addresses.

Unknown providers and unknown refresh outcomes fail closed as `unknown`. Zoho refresh success, network failure, invalid-credential or `invalid_grant`-style failure, and revoked outcomes map to stable redacted states. Gmail remains SMTP-backed in the current adapter, so OAuth-shaped Gmail configuration is `unsupported`; this foundation does not claim Gmail OAuth behavior.

## Explicit exclusions

This candidate foundation adds no callback or redirect route, REST or admin-ajax endpoint, option, schema, persistence, token retention, external HTTP/token/revoke call, OAuth scope, state/nonce/PKCE handling, provider adapter behavior, feature-gate activation, entitlement change, telemetry, or production UI. Reconnect and revoke controls are declarations only and have no side effects.
