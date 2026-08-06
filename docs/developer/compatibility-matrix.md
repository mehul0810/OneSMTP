# Compatibility Matrix

This matrix documents the synthetic WordPress email sources used to smoke-test Aculect Mail capture, routing, logging, retries, and source attribution behavior for the 0.2.0 operational reliability milestone.

The fixtures are intentionally synthetic. They use reserved `example.test` recipients, generated message identifiers, fixture-only subjects and bodies, and no provider secrets, production payloads, customer data, payment data, or real message content.

## Automated Smoke Matrix

Run the matrix with:

```bash
vendor/bin/phpunit -c phpunit.xml.dist tests/Integration/Dispatch/CompatibilityMatrixTest.php
```

| Synthetic source | Fixture shape | Expected coverage |
| --- | --- | --- |
| Core notification | Account notification-style recipient, subject, message body, and `X-OneSMTP-Synthetic-Source` header | Captures a core-like `wp_mail()` payload, routes through the active provider, writes message and attempt logs, stores the fixture source metadata, and records a sent event. |
| Form-like send | Form submission-style recipient and source metadata without storing any real form values | Confirms plugin-neutral form email payloads follow the same capture, routing, logging, source metadata, and success-event path. |
| Commerce-like metadata | Receipt/update-style metadata using fixture IDs only | Confirms ecommerce-style operational metadata remains source-attributed in the captured payload while delivery routing and logging stay provider-agnostic. |
| Membership-like metadata | Access/update-style metadata using fixture account identifiers only | Confirms membership-style operational metadata follows the same capture, routing, logging, source attribution, and success-event path. |
| Background job send | Queue/task-style metadata with a retryable provider failure | Confirms background job-style sends capture source metadata, log the failed attempt, classify the failure as retryable, schedule the next attempt through Action Scheduler, and record retry scheduling metadata. |

## Supported Attribution Contract

Aculect Mail 0.2.0 does not add a separate source table or public source schema for compatibility testing. Source attribution in this smoke matrix is intentionally limited to the currently supported capture contract:

- fixture metadata included in the captured `wp_mail()` attributes;
- stable `X-OneSMTP-Message-ID` lineage headers;
- synthetic `X-OneSMTP-Synthetic-Source` headers used only by the test matrix;
- message, attempt, and event rows that preserve provider, trigger, result, retry, and lineage state.

Admin log rendering and exports remain privacy-safe: they summarize recipients and delivery state without exposing raw recipient addresses, message bodies, raw headers, provider secrets, tokens, or raw payload JSON.

## Manual Reproduction Checklist

If the automated test cannot be run in an environment, reproduce the same coverage with a disposable local site and fixture-only data:

1. Configure one active test provider adapter or local mail sink.
2. Send one fixture email for each source family above using only `example.test` recipients.
3. Include a stable `X-OneSMTP-Message-ID` header and a source metadata field in each fixture payload.
4. Confirm each successful fixture creates one message row, one sent attempt row, and one sent event.
5. Force one retryable background-job fixture failure and confirm the failed attempt row, retry-scheduled message state, scheduled Action Scheduler job, and retry event.
6. Confirm admin-facing logs and exports do not reveal raw recipients, bodies, headers, provider secrets, tokens, or raw payload JSON.
