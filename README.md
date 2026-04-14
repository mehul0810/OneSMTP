# OneSMTP

Enterprise-grade WordPress SMTP orchestration plugin focused on reliable email delivery.

OneSMTP routes transactional emails across multiple providers with automatic failover, controlled retries, and operational logging so critical emails keep moving even during provider outages.

## MVP Capabilities

- Primary and secondary provider support
- Smart provider rotation when multiple providers are configured
- Email logging with provider-level delivery context
- Action Scheduler-powered retry workflow with auto-switching behavior
- Manual resend with provider override
- Provider support target: PHP mail, Gmail, SendGrid, Postmark, Brevo

## Delivery + Retry Behavior (MVP)

- First send starts with selected provider strategy (primary/rotation)
- On 2 consecutive failures for the same message attempt, OneSMTP auto-switches provider
- Retries continue with provider switching until max 6 attempts
- Retries are scheduled via Action Scheduler for reliability and non-blocking processing

## Logging + Retention

- OneSMTP records delivery attempts and provider outcomes in custom database tables
- Default log retention is 30 days
- Retention can be extended up to 120 days through a plugin filter

## Docs

- Admin docs: `docs/admin/`
- Developer docs: `docs/developer/`
- Policies: `docs/policies/`
- Templates: `docs/templates/`

## Contributing

See `CONTRIBUTING.md` and `CODE_STANDARDS.md` before opening pull requests.

## Changelog

See `CHANGELOG.md` for release history.
