# Email Logs

## What is Logged

- Message attempt status
- Provider used for each attempt
- Timestamp and error outcome details
- Retry attempt number

## Storage

Logs are written to custom OneSMTP database tables for queryability and operational reporting.

## Retention

- Default: 30 days
- Extendable via filter: up to 120 days
