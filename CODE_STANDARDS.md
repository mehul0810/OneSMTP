# Code Standards

## PHP + WordPress Standards

- Follow WordPress Coding Standards (WPCS).
- Use strict input validation/sanitization for all settings and request parameters.
- Escape all output in admin views.
- Verify nonce + capability checks for state-changing actions.

## Architecture Conventions

- Keep transport/provider adapters isolated from routing logic.
- Keep retry orchestration centralized (Action Scheduler handlers).
- Keep logging writes centralized through a logging service.
- Avoid business logic inside templates/views.

## Data + Storage

- Use custom database tables for email attempts, status, and provider metadata.
- Schema changes require migration notes in developer docs.
- Never store provider secrets in logs.

## Reliability Rules

- Retry workflows must be idempotent where feasible.
- Provider switch behavior must remain deterministic and testable.
- Respect max retry constraints (MVP: 6 attempts).

## Retention Rules

- Default email log retention: 30 days.
- Allow extension via filter up to 120 days.
- Purge jobs must run on schedule and fail safely.

## Testing Expectations

- Add/update unit tests for routing and retry decisions.
- Add integration tests for Action Scheduler job behavior where possible.
- Include regression coverage for provider-switching on repeated failures.
