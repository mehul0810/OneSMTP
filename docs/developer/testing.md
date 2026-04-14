# OneSMTP Testing Baseline (H1/H2)

This document defines the QA baseline scaffold for MVP deliverability behavior.

## Scope (MVP Risk-First)

- Provider failover: primary to secondary switch after repeated failures.
- Provider rotation: deterministic behavior when 3+ providers exist.
- Retry policy: capped retries (`max = 6`) with stop conditions.
- Retention policy: default and bounded log retention via filter.
- Manual resend behavior: provider override and lineage.
- Logging: attempt-level events and final terminal status.

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
- PHPUnit (after dependencies installed):
  - `vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Core/RetentionPolicyTest.php`
  - `vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Queue/RetrySchedulerTest.php`
  - `vendor/bin/phpunit -c phpunit.xml.dist tests/Unit/Dispatch/DefaultDispatchPolicyTest.php`
  - `vendor/bin/phpunit -c phpunit.xml.dist`
- Current expected result:
  - New Core/Queue tests should pass.
  - Existing policy/integration/e2e placeholders intentionally report `incomplete` until core behavior lands.

## Pending / Blocked

- `DefaultDispatchPolicy` provider-selection logic is still TODO.
- `FailoverPolicy` and `RetryPolicy` concrete classes are not yet implemented under `src/`.
- Integration tests need repository + persistence wiring for attempts/logs.
- E2E tests need admin UI routes and browser-runner setup.

## Regression Guard Backlog (Priority)

1. Assert switch to secondary on second failure of primary provider.
2. Assert no attempt beyond retry `6` and terminal failed state persists.
3. Assert deterministic provider rotation order for 3+ providers.
4. Assert manual resend honors explicitly selected provider.
5. Assert logs include provider, attempt number, result, and error reason per attempt.
6. Assert idempotency guard prevents duplicate sends on timeout ambiguity.

## CI Layering (Target)

1. `lint` (WPCS): hard fail on errors.
2. `test` (PHPUnit unit suite as required gate).
3. `analyze` (PHPStan).
4. E2E smoke (non-blocking initially, required when stable).
