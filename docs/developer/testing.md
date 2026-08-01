# OneSMTP Testing Baseline

> The release gate and evidence contract lives in the repository-root [`TESTING.md`](../../TESTING.md). This document keeps the detailed behavior matrix and test inventory.

This document defines the QA baseline scaffold for MVP deliverability behavior.

## Scope (MVP Risk-First)

- Provider failover: primary to secondary switch after repeated failures.
- Provider rotation: deterministic behavior when 3+ providers exist.
- Retry policy: capped retries (`max = 6`) with stop conditions.
- Scheduler fallback: unavailable background queue never creates a hidden retry job and records a failure event instead.
- Retention policy: default and bounded log retention via filter.
- Duplicate-attempt prevention: avoid re-enqueueing identical message/attempt jobs.
- Manual resend behavior: provider override and lineage.
- Logging: attempt-level events and final terminal status.
- Compatibility matrix: representative synthetic WordPress email sources verify capture, routing, logging, retry scheduling, and source attribution.

## Current Test Layout

```text
tests/
  bootstrap.php
  SmokeTest.php
  Support/
    PolicyFixtures.php
  Unit/
    Core/
      RetentionPolicyTest.php
    Dispatch/
      DefaultDispatchPolicyTest.php
    Policy/
      FailoverPolicyTest.php
      RetryPolicyTest.php
    Queue/
      RetrySchedulerTest.php
  Integration/
    Dispatch/
      CompatibilityMatrixTest.php
      ConcurrencyIdempotencyTest.php
      LoggingIntegrationTest.php
      ResendFlowTest.php
  E2E/
    Admin/
      DeliverabilitySmokeTest.php
```

## Runnable Now

- Syntax checks:
  - `php -l tests/bootstrap.php`
  - `php -l tests/Unit/Core/RetentionPolicyTest.php`
  - `php -l tests/Unit/Queue/RetrySchedulerTest.php`
  - `php -l tests/Unit/Dispatch/DefaultDispatchPolicyTest.php`
  - `php -l tests/Unit/Policy/RetryPolicyTest.php`
  - `php -l tests/Unit/Policy/FailoverPolicyTest.php`
  - `php -l tests/Integration/Dispatch/ConcurrencyIdempotencyTest.php`
- PHPUnit (after dependencies installed):
  - `vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Core/RetentionPolicyTest.php`
  - `vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Queue/RetrySchedulerTest.php`
  - `vendor/bin/phpunit -c phpunit.xml.dist tests/Integration/Dispatch/CompatibilityMatrixTest.php`
  - `vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Policy/RetryPolicyTest.php`
  - `vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Policy/FailoverPolicyTest.php`
  - `vendor/bin/phpunit -c phpunit.xml.dist`
- Browser/admin smoke:
  - `npm run test:e2e`
- Current expected result:
  - New core/queue/policy tests pass as executable policy specs.
  - Retry scheduler coverage includes both available and unavailable Action Scheduler states.
  - Compatibility matrix coverage uses synthetic `example.test` fixtures only and does not require production recipients, message bodies, provider secrets, or raw external payloads.
  - Integration/E2E placeholders intentionally report `incomplete` until core behavior lands.
  - Playwright renders a deterministic PHP-generated admin fixture in Chromium and checks the admin shell, primary navigation, setup/test email form state, provider form submission state, logs redaction, queue diagnostics, alert history rendering, acknowledgement form state, and alert context redaction without real credentials, external sends, private recipients, raw message bodies, full headers, or provider payloads.

## Pending / Blocked

- `DefaultDispatchPolicy` provider-selection logic is still TODO.
- Concrete `FailoverPolicy`/`RetryPolicy` classes are not yet implemented under `src/`.
- Integration tests need repository + persistence wiring for attempts/logs.
- Browser smoke currently uses a controlled PHP-rendered admin fixture instead of a live WordPress login/session. It proves the release-ready admin markup and form wiring in Chromium, but does not yet prove wp-admin authentication, real routing, or real provider delivery.

## Regression Guard Backlog (Priority)

1. Assert switch to secondary on second failure of primary provider via production dispatch policy.
2. Assert no attempt beyond retry `6` and terminal failed state persists in storage.
3. Assert deterministic provider rotation order for 3+ providers.
4. Assert manual resend honors explicitly selected provider.
5. Assert logs include provider, attempt number, result, and error reason per attempt.
6. Assert idempotency guard prevents duplicate sends on timeout ambiguity.

## CI Layering (Target)

1. `lint` (changed-file WPCS subset): hard fail on actionable errors.
2. `test` (PHPUnit unit suite as required gate).
3. `analyze` (PHPStan).
4. E2E smoke (non-blocking initially, required when stable).
