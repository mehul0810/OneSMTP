# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog and this project follows Semantic Versioning.

## [0.4.0] - Unreleased

- Pro provider reliability scoring based on aggregate success, failure, retry, switch-away, and latency history, with sample-quality labels and non-SLA guidance.
- Pro Compliance controls for bounded site-local log retention and fixed privacy-safe CSV export profiles, preserving the existing safe operational export by default.
- Pro advanced reports for provider attempts, message statuses, daily trends, failure categories, and privacy-safe subject groups, using bounded time-window queries.
- Additive provider adapter descriptors, bounded credential schemas, catalog validation, and developer authoring documentation.

### Added

- Default-deny Pro capability gates with separate entitlement and rollout checks.
- Clear disabled states for upcoming Pro modules without blocking core delivery workflows.
- Pro-gated advanced alert escalation with multiple email/HTTPS webhook destinations, deterministic repeated-failure thresholds, and privacy-safe payload/context handling.
- Pro-gated conditional routing rules for sender, recipient, subject, content, and source attribution with bounded in-memory matching and deterministic priority conflict resolution.
- Pro routing simulation for saved or unsaved candidate rules with bounded sample fields, provider eligibility explanations, and no-delivery/no-persistence guarantees.

## [0.3.0] - Unreleased

### Added

- Quiet in-product SureMail compatibility analysis and opt-in default-connection import, with inactive-by-default providers and AES-GCM credential re-encryption.
- Zoho Mail, Emailit, and Netcore transactional API adapters.
- Explicit staging simulation mode that captures outgoing mail as Simulated without contacting a provider.
- Delivery-ownership enforcement that pauses Aculect scheduled work when SureMail owns the WordPress mail function.
- Provider test logging, attachment-safe SMTP delivery, Emailit idempotency, and Zoho OAuth refresh-token support.
- Internal admin alert event history with privacy-safe context previews and nonce-protected acknowledgement tracking.
- Internal smart-routing evaluator core for deterministic in-memory rule evaluation before default dispatch fallback.

### Changed

- Provider Connect and Manage flows now advance from concise connection settings directly to an inline provider test email step.
- Removed the global provider, retry scheduler, and mail-conflict admin notices; their underlying diagnostics remain available in the relevant admin workflows.
- Renamed the user-facing plugin product from OneSMTP to Aculect Mail while retaining the `onesmtp` slug, text domain, PHP namespace, stored settings, REST routes, and diagnostic headers for upgrade compatibility.
- Added an enforceable changed-PHP-files PHPCS gate for pull requests while full-tree WPCS cleanup remains advisory.
- Refined the admin shell into progressive hash-linked workspaces with active navigation, contextual status rails, and responsive form and table constraints.
- Reorganized the admin IA into Overview, Providers, Routing, Delivery, Analytics, and Settings, and documented the Aculect ecosystem design tokens and release/testing/security contracts.
- Adopted the WordPress Design System Figma reference and official `@wordpress/*` packages as the standard for future interactive admin surfaces.
- Simplified configured provider rows: connection health and routing details remain in the row, while Manage opens the inline editor without exposing stored credentials.
- Lazy-loaded admin modules keep the initial application bundle small while loading DataViews only on screens that need it.

## [0.2.0] - 2026-06-26

### Added

- Providers admin now shows privacy-safe circuit health and open-until context for delivery providers.
- Sender identity settings for From Email, From Name, Reply-To, and BCC with explicit force controls.
- Initial documentation scaffold for admin, developer, policies, and templates.
- Baseline contribution and coding standards documentation.
- Changelog policy and feature documentation update protocol.
- Product design contract for admin UI, docs, and launch-asset decisions.
- Translation template generation workflow and baseline `languages/onesmtp.pot` release artifact.
- Minimal admin menu and settings shell for providers, logs, diagnostics, and settings entry points.
- Retry scheduler health detection with an admin notice when Action Scheduler is unavailable.
- REST route schemas and strict request validation for provider and message operations.
- Provider-specific test email REST flow with safe success and failure response metadata.
- Server-rendered provider management UI for MVP provider setup, activation, priority, weight, and safe credential entry.
- WordPress.org-ready readme metadata, 0.1.0 release notes draft, and screenshot checklist.
- First-run setup wizard for sender identity, first provider configuration, backup provider prompt, test email verification, and setup log confirmation.
- Privacy-safe admin email log list and detail views with delivery lineage, safe recipient metadata, attempt status, provider, retry, and redacted error context.
- Admin email log filtering by status, provider, date range, lineage/search-safe metadata, pagination, and nonce-protected CSV export with safe summary fields only.
- Manual resend controls on log details with optional eligible-provider override and lineage-linked delivery attempts.
- Conservative conflict detection notice for active mail delivery plugins and mail-related hooks.
- Provider capability matrix for comparing MVP provider delivery, testing, OAuth, attachment, and message identifier support before credential entry.
- Safe failure categories for delivery attempts, covering retryable, terminal, authentication, quota, timeout, and policy outcomes.
- Privacy-safe queue diagnostics in admin with scheduler availability, queue status, overdue retry counts, and recovery actions.
- Privacy-safe diagnostic report download with environment/plugin metadata, provider summaries, queue state, recent failure categories, and redaction metadata.
- WP-CLI diagnostics for privacy-safe provider summaries, queue/scheduler state, and recent failure categories.
- Optional site-wide per-minute, hourly, and daily delivery limits with Action Scheduler-backed deferral when capacity is exhausted.
- Configurable terminal failure alerts for admin email and HTTPS webhook destinations with privacy-safe payloads and throttling.
- Privacy-safe dashboard metrics for sent, failed, retried, pending, and failover activity with provider breakdowns.
- Admin accessibility QA coverage and a 0.2.0 keyboard review checklist for Aculect Mail admin screens, tables, forms, notices, and long-content states.
- Compatibility test matrix covering synthetic core notification, form-like, commerce-like, membership-like, and background job email sources.
- Privacy-safe mail source attribution for plugins, themes, WordPress core, and unknown origins in admin email logs.
- Large synthetic log-table benchmark coverage for admin log list, filter, export, detail, and schema index regression signals.
- Privacy-safe settings import/export workflow with provider secrets, credentials, tokens, webhook URLs, raw recipient destinations, headers, message bodies, and payload JSON excluded by default.
- Provider DNS authentication readiness guidance with privacy-safe SPF, DKIM, and DMARC TXT checks for configured sender domains.
- Optional background sending mode that queues normal mail through Action Scheduler while keeping provider tests and manual resends synchronous.
- Optional attachment metadata logging for email logs with default-off storage, admin privacy warnings, and raw path/content exclusion.
- Bulk resend controls for selected failed log entries and safe log-summary forwarding to the verified WordPress admin email address.
- Optional weekly delivery summary email with privacy-safe sent, failed, retried, pending, and provider failover aggregates.

### Changed

- Documented the `main`, `develop`, and `release/*` branch workflow and extended CI push coverage to the integration and stabilization branches.
- Provider credential recovery now preserves unavailable stored credentials during blank-field updates and supports safe re-entry from the Providers screen.
- Retry scheduling now fails closed and records a scheduling failure event when Action Scheduler is unavailable instead of reporting a queued retry.
- Terminal, authentication, and policy failures now stop retry scheduling early while preserving redacted log context.
- Queue backpressure now defers rate-limited mail instead of over-sending when configured delivery limits are exhausted.

## [0.1.0] - 2026-04-14

### Added

- Project bootstrap with initial README.
