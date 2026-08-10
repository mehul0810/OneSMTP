# Aculect Mail

Enterprise-grade WordPress SMTP orchestration plugin focused on reliable email delivery.

Aculect Mail routes transactional emails across multiple providers with automatic failover, controlled retries, and operational logging so critical emails keep moving even during provider outages.

> Aculect Mail was previously named OneSMTP. Existing installations retain the `onesmtp` plugin slug and internal identifiers so upgrades preserve settings and delivery history.

## MVP Capabilities

- Primary and secondary provider support
- Smart provider rotation when multiple providers are configured
- Email logging with provider-level delivery context
- Action Scheduler-powered retry workflow with auto-switching behavior
- Admin-visible retry scheduler health warning when Action Scheduler is unavailable
- Manual resend with provider override
- Provider support: Amazon SES, Brevo, Elastic Email, Gmail, Mailchimp Transactional, MailerSend, Mailgun, Mailjet, PHP mail, Postmark, Resend, SendGrid, SMTP2GO, SparkPost, ZeptoMail, and generic SMTP

## Pro candidate scope (0.4.0, unreleased)

The `release/0.4.0` branch is an unreleased candidate. Its optional Pro-ready
workflows are documented for implementation and review; they are not a public
release, purchase offer, or license-activation promise. All Pro modules use
default-deny entitlement and rollout gates, and the disabled states are inert.

Candidate workflows currently implemented behind those gates are conditional
routing and simulation, provider reliability and bounded advanced reports,
bounded compliance retention and privacy-safe export profiles, advanced
terminal-failure alert escalation, multisite network settings and bounded
network log summaries, and per-provider minute/hour/day sending budgets behind
`provider_quota_budgets`, plus Mailgun provider-event ingestion behind
`provider_events`, and default-off site-local bounce/complaint suppression
behind `bounce_suppression`. Suppression derives only from authenticated
normalized Mailgun hard-bounce and complaint events and blocks a complete
message on an active match.
Quota deferrals for attachment-bearing messages fail
closed without scheduling a retry when sanitized data cannot reproduce the
files. Core provider setup, sending, failover, queues, retries, logs, manual
resend, provider tests, troubleshooting, and normal single-site behavior stay
complete without Pro.

See [Pro-ready onboarding](docs/admin/pro-onboarding.md), the
[developer gate contract](docs/developer/pro-capability-gates.md), and the
[0.4.0 claim ledger](docs/release/0.4.0-pro-claim-ledger.md) for boundaries and
evidence. Do not infer availability, pricing, tiers, site counts, support/SLA,
hosted-service, telemetry, privacy, or release-date claims from this section.

## Delivery + Retry Behavior (MVP)

- First send starts with selected provider strategy (primary/rotation)
- On a provider failure, Aculect Mail tries another healthy active provider when one is available
- Retries continue with provider switching until max 6 attempts
- Retries are scheduled via Action Scheduler for reliability and non-blocking processing

## Logging + Retention

- Aculect Mail records delivery attempts and provider outcomes in custom database tables
- Default log retention is 30 days
- Retention can be extended up to 120 days through a plugin filter
- The 0.4.0 candidate's Pro Compliance controls add bounded 7/30/90/120-day presets or a 1-120-day site-local custom duration; scheduled pruning follows the selected policy
- CSV exports remain privacy-safe by default, with optional 0.4.0 candidate Pro fixed profiles for operational, audit, or minimal summaries

## Performance Baseline

- Baseline and targets: `docs/developer/performance/baseline-and-benchmarking.md`
- CI smoke workflow: `docs/developer/performance/ci-smoke.md`
- Run lightweight harness: `./scripts/benchmarks/run-baseline.sh smoke`
- Output artifacts are written to `artifacts/perf/<profile>-<timestamp>/`

## Docs

- Admin docs: `docs/admin/`
- Pro onboarding: `docs/admin/pro-onboarding.md`
- Multisite network: `docs/admin/multisite-network.md`
- Developer docs: `docs/developer/`
- Pro capability gates: `docs/developer/pro-capability-gates.md`
- 0.4.0 Pro claim ledger: `docs/release/0.4.0-pro-claim-ledger.md`
- Compatibility matrix: `docs/developer/compatibility-matrix.md`
- Internationalization workflow: `docs/developer/i18n.md`
- Policies: `docs/policies/`
- Templates: `docs/templates/`
- Product design contract: `DESIGN.md`
- WordPress Design System contract: `docs/developer/design-system.md`
- Testing contract: `TESTING.md`
- Release runbook: `RELEASE.md`
- Security policy: `SECURITY.md`

## Contributing

See `CONTRIBUTING.md` and `CODE_STANDARDS.md` before opening pull requests.

## Changelog

See `CHANGELOG.md` for release history.
