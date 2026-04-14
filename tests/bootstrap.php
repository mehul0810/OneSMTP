<?php

declare(strict_types=1);

/**
 * Shared bootstrap for OneSMTP tests.
 */
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];

foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        break;
    }
}

if (! function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        if (! isset($GLOBALS['onesmtp_test_filters'])) {
            $GLOBALS['onesmtp_test_filters'] = [];
        }

        if (! isset($GLOBALS['onesmtp_test_filters'][$hook])) {
            $GLOBALS['onesmtp_test_filters'][$hook] = [];
        }

        $GLOBALS['onesmtp_test_filters'][$hook][] = $callback;

        return true;
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $callbacks = $GLOBALS['onesmtp_test_filters'][$hook] ?? [];

        foreach ($callbacks as $callback) {
            $value = $callback($value, ...$args);
        }

        return $value;
    }
}

if (! function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        if (! isset($GLOBALS['onesmtp_test_actions'])) {
            $GLOBALS['onesmtp_test_actions'] = [];
        }

        $GLOBALS['onesmtp_test_actions'][] = [
            'hook' => $hook,
            'callback' => $callback,
            'priority' => $priority,
            'accepted_args' => $acceptedArgs,
        ];

        return true;
    }
}

if (! function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void
    {
        if (! isset($GLOBALS['onesmtp_test_fired_actions'])) {
            $GLOBALS['onesmtp_test_fired_actions'] = [];
        }

        $GLOBALS['onesmtp_test_fired_actions'][] = [
            'hook' => $hook,
            'args' => $args,
        ];
    }
}

if (! function_exists('as_has_scheduled_action')) {
    function as_has_scheduled_action(string $hook, array $args, string $group = ''): bool
    {
        if (($GLOBALS['onesmtp_test_action_scheduler_available'] ?? true) === false) {
            return false;
        }

        $index = $hook . '|' . $group . '|' . md5((string) wp_json_encode($args));

        return isset($GLOBALS['onesmtp_test_scheduled_actions'][$index]);
    }
}

if (! function_exists('as_schedule_single_action')) {
    function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = ''): int
    {
        if (($GLOBALS['onesmtp_test_action_scheduler_available'] ?? true) === false) {
            return 0;
        }

        if (! isset($GLOBALS['onesmtp_test_scheduled_actions'])) {
            $GLOBALS['onesmtp_test_scheduled_actions'] = [];
        }

        $index = $hook . '|' . $group . '|' . md5((string) wp_json_encode($args));

        $GLOBALS['onesmtp_test_scheduled_actions'][$index] = [
            'timestamp' => $timestamp,
            'hook' => $hook,
            'args' => $args,
            'group' => $group,
        ];

        return 1;
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
    {
        return json_encode($value, $flags, $depth);
    }
}

if (! function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string
    {
        if ($type === 'mysql') {
            return gmdate('Y-m-d H:i:s');
        }

        return (string) time();
    }
}

if (! function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return 1;
    }
}

if (! function_exists('wp_using_ext_object_cache')) {
    function wp_using_ext_object_cache(): bool
    {
        return false;
    }
}

if (! function_exists('wp_cache_add')) {
    function wp_cache_add(string $key, mixed $data, string $group = '', int $expire = 0): bool
    {
        return false;
    }
}

if (! function_exists('wp_cache_delete')) {
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        return true;
    }
}

if (! function_exists('get_transient')) {
    function get_transient(string $transient): mixed
    {
        return $GLOBALS['onesmtp_test_transients'][$transient] ?? false;
    }
}

if (! function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool
    {
        if (! isset($GLOBALS['onesmtp_test_transients'])) {
            $GLOBALS['onesmtp_test_transients'] = [];
        }

        $GLOBALS['onesmtp_test_transients'][$transient] = $value;

        return true;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        unset($GLOBALS['onesmtp_test_transients'][$transient]);

        return true;
    }
}

if (! file_exists(__DIR__ . '/../vendor/autoload.php') && ! file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    fwrite(
        STDERR,
        "[OneSMTP tests] Composer autoload not found. Run 'composer install' before executing PHPUnit.\n"
    );
}
