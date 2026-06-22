=== OneSMTP ===
Contributors: onesmtp
Tags: smtp, email, transactional email, email logs, failover
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reliable transactional email delivery for WordPress with provider configuration, failover, retries, logs, resend, and provider test email workflows.

== Description ==

OneSMTP helps WordPress administrators route transactional email through configured providers with operational controls for delivery reliability.

Version 0.1.0 includes provider configuration for PHP mail, Gmail, SendGrid, Postmark, Brevo, and SMTP-style delivery adapters; capture and send orchestration for WordPress email; provider failover and rotation; Action Scheduler-powered retries; delivery logs; manual resend with provider override; and provider-specific test email support.

OneSMTP also keeps credential handling behind a safer boundary: provider data returned to admin REST workflows is redacted, sensitive configuration values are not exposed through public read paths, and release packaging excludes development-only artifacts.

Current 0.1.0 scope is focused on the plugin foundation and release-readiness workflow. Do not treat this release as a claim of advanced reporting, hosted delivery service, or unshipped paid functionality.

= Reliability features =

* Configure primary, secondary, or multiple providers.
* Rotate across active providers according to configured priority and weight.
* Switch providers after repeated delivery failures.
* Retry failed messages through Action Scheduler, up to the configured 0.1.0 attempt limit.
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
2. Activate OneSMTP from the Plugins screen.
3. Configure at least one provider in the OneSMTP settings workflow.
4. Send a provider test email to confirm credentials and delivery.
5. Review Email Logs to confirm provider selection, status, and retry behavior.
6. Configure a secondary provider if you want failover or rotation coverage.

== Frequently Asked Questions ==

= Which providers are included in 0.1.0? =

The 0.1.0 codebase includes adapters and setup documentation for PHP mail, Gmail, SendGrid, Postmark, Brevo, and SMTP-style delivery.

= Does OneSMTP resend failed emails? =

Yes. The 0.1.0 workflow includes manual resend support for stored messages, with an optional provider override.

= How many retry attempts are used? =

The 0.1.0 retry flow uses Action Scheduler and allows up to six attempts for a failed email flow.

= What happens if the retry scheduler is unavailable? =

OneSMTP records a scheduler-unavailable failure event instead of silently pretending that a retry was queued.

= Are credentials shown in logs or provider responses? =

The 0.1.0 codebase includes redaction and secret-handling boundaries for provider configuration. Administrators should still use scoped provider credentials and rotate them according to their provider policy.

= Is this a hosted email delivery service? =

No. OneSMTP is a WordPress plugin that routes site email through providers you configure.

== Screenshots ==

1. Provider setup screen with configured provider type, priority, weight, and active state.
2. Provider test email flow showing a safe success or failure result.
3. Email log list showing message status, provider, attempt number, and delivery result.
4. Failed message detail or action view with manual resend and provider override.
5. Retry scheduler warning shown when Action Scheduler is unavailable.
6. Release package contents showing production files and excluded development artifacts.

== Changelog ==

= 0.1.0 =

* Initial OneSMTP release foundation.
* Added provider configuration and delivery adapters for supported 0.1.0 provider types.
* Added capture/send orchestration, provider failover and rotation, retry scheduling, email logs, manual resend, and provider test email workflows.
* Added safer credential handling and redaction boundaries for provider configuration.
* Added documentation, translation template generation, and release packaging workflow support.

== Upgrade Notice ==

= 0.1.0 =

Initial release. Configure at least one provider and send a test email after activation.
