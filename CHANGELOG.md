# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog and this project follows Semantic Versioning.

## [Unreleased]

### Added

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
- Manual resend controls on log details with optional eligible-provider override and lineage-linked delivery attempts.
- Conservative conflict detection notice for active mail delivery plugins and mail-related hooks.
- Provider capability matrix for comparing MVP provider delivery, testing, OAuth, attachment, and message identifier support before credential entry.
- Safe failure categories for delivery attempts, covering retryable, terminal, authentication, quota, timeout, and policy outcomes.

### Changed

- Documented the `main`, `develop`, and `release/*` branch workflow and extended CI push coverage to the integration and stabilization branches.
- Retry scheduling now fails closed and records a scheduling failure event when Action Scheduler is unavailable instead of reporting a queued retry.
- Terminal, authentication, and policy failures now stop retry scheduling early while preserving redacted log context.

## [0.1.0] - 2026-04-14

### Added

- Project bootstrap with initial README.
