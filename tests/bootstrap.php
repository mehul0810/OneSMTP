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

if (! function_exists('as_has_scheduled_action')) {
    function as_has_scheduled_action(string $hook, array $args, string $group = ''): bool
    {
        $index = $hook . '|' . $group . '|' . md5((string) wp_json_encode($args));

        return isset($GLOBALS['onesmtp_test_scheduled_actions'][$index]);
    }
}

if (! function_exists('as_schedule_single_action')) {
    function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = ''): int
    {
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

if (! file_exists(__DIR__ . '/../vendor/autoload.php') && ! file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    fwrite(
        STDERR,
        "[OneSMTP tests] Composer autoload not found. Run 'composer install' before executing PHPUnit.\n"
    );
}
