# Pro-ready onboarding (0.4.0 candidate)

This page describes the optional Pro-ready workflows present on the
`release/0.4.0` branch. **0.4.0 is unreleased candidate scope.** The public
plugin listing, release tags, and package metadata are separate release
surfaces; do not treat this page as a purchase, availability, or release
announcement.

## Start with the core workflow

Pro is not required to send mail. Complete the core setup first:

1. [Configure a provider](provider-setup.md) and keep credentials scoped to
   the site and environment.
2. Send a provider test email and confirm the result in [Email Logs](email-logs.md).
3. Add a secondary provider when you need failover or rotation coverage.
4. Confirm Action Scheduler is available before relying on queued retries. If
   it is unavailable, the plugin fails closed and records the scheduling
   failure rather than pretending a retry was queued.
5. Use [Troubleshooting](troubleshooting.md) for provider, queue, and delivery
   recovery steps.

Core provider setup, sending, failover, rotation, queues, retries, logs,
manual resend, provider tests, and troubleshooting remain self-sufficient
without Pro.

## Candidate Pro workflows

Each shipped workflow below requires both the matching Pro entitlement and its
internal rollout flag. The normal WordPress capability checks still apply.
The controls are default-deny and do not change core delivery when unavailable.

| Workflow | Admin surface | Gate | Safe fallback and boundary |
| --- | --- | --- | --- |
| Conditional routing rules and simulation | Routing | `smart_routing` | The normal healthy-provider route remains the fallback. Simulation uses bounded in-memory samples only; it does not call a provider, send or queue mail, write message/attempt/event/audit records, or change saved rules. See [Failover and Rotation](failover-and-rotation.md). |
| Provider reliability and advanced reports | Analytics | `advanced_analytics` plus log visibility | Core summary analytics and provider tables remain available. Pro views use bounded aggregate history; they exclude bodies, recipients, credentials, payloads, and raw event context. Scores are operational comparisons, not inbox-placement or provider-SLA measurements. See [Advanced reports](advanced-reports.md). |
| Compliance retention and export profiles | Advanced settings and Activity | `compliance_controls` | Core 30-day retention, filter-based extension, and the safe operational CSV remain available. Pro retention is site-local, bounded to 1–120 days, and does not purge immediately; export profiles are fixed allowlists. See [Email Logs](email-logs.md). |
| Advanced alert escalation | Advanced settings | `advanced_alerts` | Existing core failure alerts are unchanged. Pro escalation sends only validated email or HTTPS webhook destinations at a configured repeated-failure threshold, with safe operational metadata and throttling. See [Troubleshooting](troubleshooting.md). |
| Mailgun provider-event ingestion | Providers → Mailgun connection | `provider_events` | Candidate-shipped Mailgun-only HTTPS JSON webhooks verify the Webhook Signing Key, atomically burn replay tokens, and retain normalized privacy-safe records. Suppression is separately gated below. See [Provider setup](provider-setup.md). |
| Bounce and complaint suppression | Advanced settings | `bounce_suppression` plus `provider_events` | Default-off site-local suppression derives only from authenticated normalized Mailgun hard-bounce and complaint events. An unexpired exact HMAC match blocks the complete message across initial, queued, retry, and manual resend paths; rows inherit bounded retention. |
| Multisite network settings and bounded network log summaries | Network admin Settings → Aculect Mail and network log view | `multisite_management` plus `manage_network_options` | Network defaults and site overrides are allowlisted and resolved at read time. Summaries are bounded and privacy-safe; provider credentials, payloads, bodies, full recipients, headers, and paths remain site-local/excluded. Single-site and normal site-admin behavior remain unchanged. See [Multisite network](multisite-network.md). |
| Provider sending budgets ([#54](https://github.com/mehul0810/aculect-mail/issues/54)) | Provider connection settings and delivery routing | `provider_quota_budgets` | Bound each provider's minute, hour, and day attempt windows. Quota-exhausted providers are skipped; an all-exhausted eligible pool defers to the earliest next capacity. Attachment-bearing quota deferrals fail closed with `attachment_quota_deferral_not_persisted` before UUID resolution or scheduling, so no retry can omit files. See [Provider setup](provider-setup.md) and [Failover and Rotation](failover-and-rotation.md). |

The status **Available with Pro** is descriptive of the capability boundary,
not a claim that a plan, license, purchase flow, or upgrade URL is available.
The candidate has no purchase CTA, operational license activation path, hosted service,
pricing, tier, site-count, support/SLA, telemetry, or privacy promise.
Its local licensing/update foundation exposes bounded contracts and honest
unavailable/error states only; it performs no external request and cannot
activate a paid entitlement or deliver an update.

## Visual reference

These fixture-backed captures show the candidate Advanced panel after the
multisite and provider-quota release tips were merged. The files are committed evidence, contain no
credentials or message data, and include both desktop and narrow viewport
states: [desktop panel screenshot](screenshots/issue-57/pro-capabilities-panel-desktop.png)
and [mobile panel screenshot](screenshots/issue-57/pro-capabilities-panel-mobile.png).
For the full claim context and captions, see the [0.4.0 claim ledger](../release/0.4.0-pro-claim-ledger.md#screenshot-inventory).

## Planned or excluded boundaries

The following are not shipped by this candidate and must not be presented as
enabled Pro workflows:

- Open/click tracking (issue
  [#40](https://github.com/mehul0810/aculect-mail/issues/40)).
- OAuth connection lifecycle (issue
  [#50](https://github.com/mehul0810/aculect-mail/issues/50)) and provider token
  refresh, revocation, or reconnection UX (issue
  [#51](https://github.com/mehul0810/aculect-mail/issues/51)).
- A concrete license service, production activation, entitlement authority,
  update feed, authenticated package delivery, and signing-key infrastructure.
The disabled controls for these boundaries are inert. Do not add an upgrade
link or purchase instruction until the owner supplies the URL and positioning
decision.

## When a Pro control is unavailable

1. Read the state label in **Advanced**. **Available with Pro** means the
   shipped capability is gated; **Not enabled** means its rollout flag is off;
   **Planned** means the workflow is not shipped; and **Requires Pro** or
   **Not available yet** buttons are disabled controls, not actions.
2. Continue with the linked core workflow. A missing entitlement or rollout
   flag must not block sending, failover, queues, logs, or core alerts.
3. If a shipped capability was expected to be enabled, verify the entitlement
   and rollout decision with the product owner. Do not edit feature flags in a
   production site as a workaround.
