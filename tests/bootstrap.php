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

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (! defined('ONESMTP_VERSION')) {
    define('ONESMTP_VERSION', '0.1.0');
}

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        /** @var array<string,string> */
        private array $errors = [];

        /** @var array<string,mixed> */
        private array $errorData = [];

        public function __construct(string $code = '', string $message = '', mixed $data = null)
        {
            if ($code !== '') {
                $this->errors[$code] = $message;
                if ($data !== null) {
                    $this->errorData[$code] = $data;
                }
            }
        }

        public function get_error_code(): string
        {
            return array_key_first($this->errors) ?? '';
        }

        public function get_error_message(string $code = ''): string
        {
            $target = $code !== '' ? $code : $this->get_error_code();

            return $this->errors[$target] ?? '';
        }

        public function get_error_data(string $code = ''): mixed
        {
            $target = $code !== '' ? $code : $this->get_error_code();

            return $this->errorData[$target] ?? null;
        }
    }
}

if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string,mixed> */
        private array $params = [];

        /** @var mixed */
        private mixed $json = null;

        /**
         * @param array<string,mixed> $params Request params.
         */
        public function __construct(array $params = [], mixed $json = null)
        {
            $this->params = $params;
            $this->json = $json;
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        public function get_json_params(): mixed
        {
            return $this->json;
        }
    }
}

if (! class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(public mixed $data = null, public int $status = 200)
        {
        }
    }
}

if (! class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
        public const READABLE = 'GET';
        public const CREATABLE = 'POST';
        public const EDITABLE = 'POST, PUT, PATCH';
        public const DELETABLE = 'DELETE';
    }
}

if (! function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $args): bool
    {
        if (! isset($GLOBALS['onesmtp_test_rest_routes'])) {
            $GLOBALS['onesmtp_test_rest_routes'] = [];
        }

        $GLOBALS['onesmtp_test_rest_routes'][] = [
            'namespace' => $namespace,
            'route' => $route,
            'args' => $args,
        ];

        return true;
    }
}

if (! function_exists('register_uninstall_hook')) {
    function register_uninstall_hook(string $file, callable $callback): void
    {
        $GLOBALS['onesmtp_test_uninstall_hook'] = [
            'file' => $file,
            'callback' => $callback,
        ];
    }
}

if (! function_exists('add_option')) {
    function add_option(string $option, mixed $value = '', string $deprecated = '', bool $autoload = true): bool
    {
        if (! isset($GLOBALS['onesmtp_test_options'])) {
            $GLOBALS['onesmtp_test_options'] = [];
        }

        if (array_key_exists($option, $GLOBALS['onesmtp_test_options'])) {
            return false;
        }

        $GLOBALS['onesmtp_test_options'][$option] = [
            'value' => $value,
            'autoload' => $autoload,
        ];

        return true;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $option, mixed $value, bool|string|null $autoload = null): bool
    {
        if (! isset($GLOBALS['onesmtp_test_options'])) {
            $GLOBALS['onesmtp_test_options'] = [];
        }

        $GLOBALS['onesmtp_test_options'][$option] = [
            'value' => $value,
            'autoload' => $autoload,
        ];

        return true;
    }
}

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        if (! array_key_exists($option, $GLOBALS['onesmtp_test_options'] ?? [])) {
            return $default;
        }

        return $GLOBALS['onesmtp_test_options'][$option]['value'];
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        unset($GLOBALS['onesmtp_test_options'][$option]);

        return true;
    }
}

if (! function_exists('dbDelta')) {
    function dbDelta(string $queries = '', bool $execute = true): array
    {
        if (! isset($GLOBALS['onesmtp_test_dbdelta_queries'])) {
            $GLOBALS['onesmtp_test_dbdelta_queries'] = [];
        }

        $GLOBALS['onesmtp_test_dbdelta_queries'][] = $queries;

        return [];
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

if (! function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower($key)) ?? '';
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
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

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return (bool) ($GLOBALS['onesmtp_test_current_user_can'] ?? true);
    }
}

if (! function_exists('get_role')) {
    function get_role(string $role): mixed
    {
        return $GLOBALS['onesmtp_test_roles'][$role] ?? null;
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
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
