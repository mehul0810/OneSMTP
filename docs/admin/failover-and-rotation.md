# Failover and Rotation

## Primary + Secondary

OneSMTP sends via primary first and falls back to secondary when repeated failures are detected.

## Smart Rotation

When more than two providers are configured, OneSMTP rotates through available providers based on configured order/strategy.

## Failure Switch Rule (MVP)

- After 2 failures for the same email flow, OneSMTP switches provider.
- It continues switching as needed until success or max retries are reached.
