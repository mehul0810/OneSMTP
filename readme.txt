=== Aculect Mail ===
Contributors: onesmtp
Tags: smtp, email, transactional email, email logs, failover
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reliable transactional email delivery for WordPress with provider configuration, failover, retries, logs, resend, and provider test email workflows.

== Description ==

Aculect Mail helps WordPress administrators route transactional email through configured providers with operational controls for delivery reliability.

Aculect Mail was previously named OneSMTP. Existing installations retain the `onesmtp` plugin slug and internal identifiers so upgrades preserve settings and delivery history.

Version 0.3.0 includes provider configuration for Amazon SES, Brevo, Elastic Email, Emailit, Gmail, Mailchimp Transactional, MailerSend, Mailgun, Mailjet, Netcore, PHP mail, Postmark, Resend, SendGrid, SMTP2GO, SparkPost, ZeptoMail, Zoho Mail, and generic SMTP delivery adapters; capture and send orchestration for WordPress email; eligible-provider failover and rotation; Action Scheduler-powered retries; delivery logs and queue management; manual and bulk resend workflows; provider-specific test email support; operational diagnostics; rate limit backpressure; failure alerts; dashboard metrics; log filtering and export; source attribution; settings import and export with secrets excluded by default; DNS authentication guidance; optional background sending; attachment metadata privacy controls; and weekly delivery summaries.

Aculect Mail also keeps credential handling behind a safer boundary: provider data returned to admin REST workflows is redacted, sensitive configuration values are not exposed through public read paths, and release packaging excludes development-only artifacts.

The 0.3.0 workflow also adds an opt-in SureMail compatibility and migration assistant, attachment-safe SMTP handling, provider reliability and switchover metrics, and an explicit staging simulation mode. Aculect Mail does not claim guaranteed delivery: provider acceptance, recipient policy, DNS authentication, and downstream mailbox handling remain outside the plugin's control.

= Reliability features =

* Configure primary, secondary, or multiple providers.
* Rotate across active providers according to configured priority and weight.
* Switch to another eligible provider when a provider-scoped failure permits failover.
* Retry failed messages through Action Scheduler, up to the configured 0.3.0 attempt limit.
* Record provider outcomes and retry state in plugin database tables.
* Resend failed messages manually, including an optional provider override.
* Send provider test emails from the provider workflow.

= Operational boundaries =

* Logs default to 30 days of retention, with a bounded filter for site-specific retention policies.
* Retry scheduling fails closed when Action Scheduler is unavailable and records the scheduler failure path.
* Provider credential values are encrypted or redacted at the plugin boundary where implemented.
* Public metadata avoids naming or comparing competing products.

== Installation ==

1. Upload the `onesmtp` plugin directory to `/wp-content/plugins/`, or install the release ZIP through the WordPress Plugins screen.
2. Activate Aculect Mail from the Plugins screen.
3. Configure at least one provider in the Aculect Mail settings workflow.
4. Send a provider test email to confirm credentials and delivery.
5. Review Email Logs to confirm provider selection, status, and retry behavior.
6. Configure a secondary provider if you want failover or rotation coverage.

== Frequently Asked Questions ==

= Which providers are included in 0.3.0? =

The current codebase includes adapters and setup documentation for Amazon SES, Brevo, Elastic Email, Emailit, Gmail, Mailchimp Transactional, MailerSend, Mailgun, Mailjet, Netcore, PHP mail, Postmark, Resend, SendGrid, SMTP2GO, SparkPost, ZeptoMail, Zoho Mail, and generic SMTP delivery.

= Does Aculect Mail resend failed emails? =

Yes. The 0.3.0 workflow includes automatic eligible-provider failover, scheduled retries, manual resend support for stored messages, bulk resend controls for failed log entries, and optional provider override handling where eligible.

= How many retry attempts are used? =

The 0.3.0 retry flow uses Action Scheduler and allows up to six attempts for a failed email flow.

= What happens if the retry scheduler is unavailable? =

Aculect Mail records a scheduler-unavailable failure event instead of silently pretending that a retry was queued.

= Are credentials shown in logs or provider responses? =

The 0.3.0 codebase includes redaction, AES-GCM credential storage, and secret-handling boundaries for provider configuration, SureMail import, diagnostics, log exports, settings export, and provider responses. Administrators should still use scoped provider credentials and rotate them according to their provider policy.

= Is this a hosted email delivery service? =

No. Aculect Mail is a WordPress plugin that routes site email through providers you configure.

== Screenshots ==

1. Provider setup screen with configured provider type, priority, weight, and active state.
2. Provider test email flow showing a safe success or failure result.
3. Email log list showing message status, provider, attempt number, and delivery result.
4. Failed message detail or action view with manual resend and provider override.
5. Retry scheduler warning shown when Action Scheduler is unavailable.
6. Release package contents showing production files and excluded development artifacts.

== Changelog ==

= 0.3.0 =

* Added Zoho Mail OAuth refresh, Emailit, and Netcore provider adapters alongside the expanded provider catalog.
* Added delivery logs, queue controls, failover visibility, provider reliability metrics, and attachment-safe routing boundaries.
* Added a quiet SureMail compatibility card and opt-in default-connection import with credential re-encryption.
* Added explicit simulation mode for staging, recording messages as Simulated without contacting Aculect Mail providers.
* Redesigned the admin information architecture and provider connection flow with WordPress components and lazy-loaded DataViews.
* Hardened provider ownership, failure classification, idempotency, provider tests, and one-provider/one-connection enforcement.

= 0.2.0 =

* Added privacy-safe operational diagnostics, dashboard metrics, queue health visibility, failure classification, rate limit backpressure, and terminal failure alerts.
* Added log filtering, pagination, CSV export, source attribution, attachment metadata privacy controls, bulk resend, safe log-summary forwarding, and weekly delivery summaries.
* Added settings import/export with secrets excluded by default, DNS authentication readiness guidance, compatibility coverage, accessibility QA coverage, WP-CLI diagnostics, and performance benchmark coverage.
* Added optional background sending mode while keeping provider tests and manual resends synchronous.
* Improved credential recovery, retry scheduling failure behavior, terminal failure handling, and branch/release workflow documentation.

= 0.1.0 =

* Initial OneSMTP release foundation.
* Added provider configuration and delivery adapters for supported 0.1.0 provider types.
* Added capture/send orchestration, provider failover and rotation, retry scheduling, email logs, manual resend, and provider test email workflows.
* Added safer credential handling and redaction boundaries for provider configuration.
* Added documentation, translation template generation, and release packaging workflow support.

== Upgrade Notice ==

= 0.3.0 =

Review delivery ownership before enabling live sending. If SureMail is active, use the compatibility assistant and test the imported provider before changing either plugin. Re-save Zoho Mail connections with refresh credentials, verify queue health, and send a test email on staging.

= 0.2.0 =

Review provider settings, diagnostics, queue health, delivery limits, alert settings, and privacy controls after upgrading. Keep provider credentials scoped and rotate them according to provider policy.

= 0.1.0 =

Initial release. Configure at least one provider and send a test email after activation.
