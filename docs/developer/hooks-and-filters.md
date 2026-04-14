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
