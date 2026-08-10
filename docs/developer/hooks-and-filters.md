# Hooks and Filters

## Planned Public Filters

### `onesmtp_log_retention_days`

Allows overriding log retention days.

- Default: `30`
- Maximum enforced by plugin: `120`

Example:

```php
add_filter( 'onesmtp_log_retention_days', function( $days ) {
    return 90;
} );
```

The administrator-facing Pro retention control stores the selected site-local duration in `onesmtp_log_retention_days`; it does not use a network option or add multisite behavior. The scheduled `RetentionPruner` reads the same normalized value, so the selected policy is applied without a second retention source of truth.

CSV exports use fixed safe profiles owned by `OneSMTP\Logging\LogExportProfile`. The default operational profile preserves the existing summary columns. Profile input is normalized against the allowlist before output; raw database columns and payload keys are never accepted as export fields.
