# Changelog

All notable changes to this project will be documented in this file.

The format is based on Keep a Changelog and this project follows Semantic Versioning.

## [Unreleased]

### Added

- Initial documentation scaffold for admin, developer, policies, and templates.
- Baseline contribution and coding standards documentation.
- Changelog policy and feature documentation update protocol.
- Product design contract for admin UI, docs, and launch-asset decisions.
- Translation template generation workflow and baseline `languages/onesmtp.pot` release artifact.
- Retry scheduler health detection with an admin notice when Action Scheduler is unavailable.

### Changed

- Documented the `main`, `develop`, and `release/*` branch workflow and extended CI push coverage to the integration and stabilization branches.
- Retry scheduling now fails closed and records a scheduling failure event when Action Scheduler is unavailable instead of reporting a queued retry.

## [0.1.0] - 2026-04-14

### Added

- Project bootstrap with initial README.
