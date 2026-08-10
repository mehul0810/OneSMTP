# Pro capability gates

This is the developer contract for the optional Pro-ready modules on the
`release/0.4.0` candidate. It documents the gate and fallback behavior; it is
not a licensing API or a promise that Pro distribution is available.

## Gate contract

`OneSMTP\Product\FeatureGate` is the single default-deny decision point.
`FeatureGate::fromRuntime()` reads the following filters for a future Pro
runtime or fixture:

- `onesmtp_feature_flags`
- `onesmtp_pro_feature_flags`
- `onesmtp_pro_entitled`

The free plugin supplies no entitlement and no rollout flags, performs no
licensing request or network lookup, and therefore denies every optional
module. A feature is enabled only when both its entitlement and its individual
rollout flag are true. An entitlement alone returns `flag_disabled`; a missing
entitlement returns `upgrade_required`; an unknown feature ID returns `unknown`.
Unknown or malformed flag values fail closed.

The gate IDs currently defined in source are:

| ID | Candidate status | Implemented behavior |
| --- | --- | --- |
| `smart_routing` | Shipped behind the gate | Conditional routing rules and request-scoped simulation. |
| `advanced_analytics` | Shipped behind the gate | Provider reliability scoring and bounded advanced report slices. |
| `compliance_controls` | Shipped behind the gate | Site-local 1–120 day retention policy and fixed privacy-safe export profiles. |
| `advanced_alerts` | Shipped behind the gate | Repeated terminal-failure escalation to validated email or HTTPS webhook destinations. |
| `provider_events` | Candidate-shipped behind the gate | Mailgun-only signed event ingestion and privacy-safe normalized records for #63. Suppression enforcement remains the separate owner-gated #64 follow-up. |
| `multisite_management` | Shipped behind the gate | Network-admin-only allowlisted settings with explicit site inheritance/overrides and bounded privacy-safe network log summaries. Provider credentials and payload fields remain site-local. |
| `provider_quota_budgets` | Shipped behind the gate | Per-provider minute/hour/day attempt windows with deterministic capacity-aware routing and typed deferral. Attachment-bearing quota deferrals fail closed before UUID resolution or scheduling when sanitized retry data cannot reproduce files. |

`provider_events` is still candidate/release-branch scope and is not a public
release or licensing API. It does not enable suppression enforcement.

## Fallback and data boundaries

- Core provider selection, failover, queues, retries, logs, manual resend, and
  provider tests do not depend on a Pro gate.
- Conditional routing skips disabled/malformed/ineligible rules and falls back
  to the normal healthy-provider route. Simulation never enters delivery,
  queue, retry, message, attempt, event, audit, or rule-persistence paths.
- Analytics queries use bounded aggregate fields. Bodies, recipients,
  credentials, provider payloads, and raw event context do not cross the
  report boundary. Reliability scores are not inbox-placement or provider-SLA
  guarantees.
- Compliance retention is site-local and bounded to 1–120 days. Saving a
  policy changes the next scheduled pruning cutoff; it does not trigger an
  immediate purge. CSV profiles are fixed allowlists and exclude bodies,
  headers, raw recipients, payload JSON, paths, tokens, credentials, and
  provider configuration.
- Advanced alert destinations are revalidated before dispatch. Payloads carry
  operational metadata only, and core alerts continue independently when
  advanced escalation is unavailable.
- Multisite settings are network-admin-only and limited to allowlisted
  operational groups. Effective values resolve at read time with explicit
  inheritance/override state; network summaries are bounded and restore blog
  context after each per-site read/write path. Provider credentials and raw
  payload data are never copied into network settings or summaries.
- Mailgun webhooks are site-local, HTTPS-only, bounded JSON POSTs. Signature
  verification, replay hashing, and retention happen before any normalized row
  is written; no provider payload or plaintext recipient crosses the storage or
  admin boundary.
- Provider budgets are stored as bounded non-secret provider configuration. Only
  production send attempts count toward a window; provider tests do not. When
  all eligible providers are exhausted, delivery defers to the earliest next
  capacity only when the retry payload is safely reproducible. Attachment-bearing
  deferrals fail closed with `attachment_quota_deferral_not_persisted`, mark the
  message terminally failed, and enqueue no retry.

## Extension and release boundaries

The filters above are internal integration points for the candidate runtime and
fixtures, not a supported way to activate paid behavior. Do not add license
activation, purchase URLs, pricing, tiers, hosted-service claims, telemetry,
privacy promises, or public schema changes here. Owner-gated issues #40, #50,
#51, #63, #64, and #66 remain planned/excluded from this candidate. Provider
quota budgets from [issue #54](https://github.com/mehul0810/aculect-mail/issues/54) are candidate-shipped by merged [PR #166](https://github.com/mehul0810/aculect-mail/pull/166);
the issue is closed and its release-tip evidence is recorded in the claim ledger.

The additive provider adapter catalog in
[`provider-adapters.md`](provider-adapters.md) is a developer extension
contract, not a Pro entitlement. It validates built-in registrations and fails
closed for malformed declarations without changing the core adapter interface.

## Validation references

The gate and fallback contracts are covered by:

- `tests/Unit/Product/FeatureGateTest.php`
- `tests/Unit/Admin/ProCapabilitiesPanelTest.php`
- `tests/Unit/Admin/RoutingAdminTest.php`
- `tests/Unit/Dispatch/RoutingRuleSimulatorTest.php`
- `tests/Integration/Dispatch/RoutingRuleSimulationIntegrationTest.php`
- `tests/Unit/Admin/DashboardAdminTest.php` and
  `tests/Integration/Analytics/AdvancedReportsIntegrationTest.php`
- `tests/Unit/Admin/SettingsAdminTest.php`, `tests/Unit/Admin/LogAdminTest.php`,
  and `tests/Unit/Core/RetentionPolicyTest.php`
- `tests/Unit/Alerts/AdvancedFailureAlertTest.php` and
  `tests/Unit/Alerts/FailureAlertSettingsTest.php`
- `tests/E2E/admin-smoke.spec.js` with the synthetic PHP fixture
