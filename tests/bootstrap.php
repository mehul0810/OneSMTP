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

if (! defined('ONESMTP_TESTING')) {
    define('ONESMTP_TESTING', true);
}

if (! defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
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

if (! function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = ''): string
    {
        if ($show === 'name') {
            return 'Test Site';
        }

        return '';
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

if (! function_exists('add_menu_page')) {
    function add_menu_page(
        string $pageTitle,
        string $menuTitle,
        string $capability,
        string $menuSlug,
        ?callable $callback = null,
        string $iconUrl = '',
        int|float|null $position = null
    ): string {
        if (! isset($GLOBALS['onesmtp_test_admin_menu_pages'])) {
            $GLOBALS['onesmtp_test_admin_menu_pages'] = [];
        }

        $GLOBALS['onesmtp_test_admin_menu_pages'][] = [
            'page_title' => $pageTitle,
            'menu_title' => $menuTitle,
            'capability' => $capability,
            'menu_slug' => $menuSlug,
            'callback' => $callback,
            'icon_url' => $iconUrl,
            'position' => $position,
        ];

        return 'toplevel_page_' . $menuSlug;
    }
}

if (! function_exists('add_submenu_page')) {
    function add_submenu_page(
        string $parentSlug,
        string $pageTitle,
        string $menuTitle,
        string $capability,
        string $menuSlug,
        ?callable $callback = null,
        int|float|null $position = null
    ): string|false {
        if (! isset($GLOBALS['onesmtp_test_admin_submenu_pages'])) {
            $GLOBALS['onesmtp_test_admin_submenu_pages'] = [];
        }

        $GLOBALS['onesmtp_test_admin_submenu_pages'][] = [
            'parent_slug' => $parentSlug,
            'page_title' => $pageTitle,
            'menu_title' => $menuTitle,
            'capability' => $capability,
            'menu_slug' => $menuSlug,
            'callback' => $callback,
            'position' => $position,
        ];

        return $parentSlug . '_page_' . $menuSlug;
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

if (! function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook): int|false
    {
        return $GLOBALS['onesmtp_test_cron_events'][$hook]['timestamp'] ?? false;
    }
}

if (! function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool
    {
        if (! isset($GLOBALS['onesmtp_test_cron_events'])) {
            $GLOBALS['onesmtp_test_cron_events'] = [];
        }

        $GLOBALS['onesmtp_test_cron_events'][$hook] = [
            'timestamp' => $timestamp,
            'recurrence' => $recurrence,
            'hook' => $hook,
            'args' => $args,
        ];

        return true;
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

if (! function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (! function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $specialChars = true, bool $extraSpecialChars = false): string
    {
        return substr(str_repeat('a', max(1, $length)), 0, $length);
    }
}

if (! function_exists('absint')) {
    function absint(mixed $value): int
    {
        return abs((int) $value);
    }
}

if (! function_exists('sanitize_email')) {
    function sanitize_email(string $email): string
    {
        return filter_var(trim($email), FILTER_SANITIZE_EMAIL) ?: '';
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

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('wp_nonce_field')) {
    function wp_nonce_field(string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $display = true): string
    {
        $field = '<input type="hidden" name="' . esc_attr($name) . '" value="test-nonce">';
        if ($display) {
            echo $field;
        }

        return $field;
    }
}

if (! function_exists('check_admin_referer')) {
    function check_admin_referer(string $action = '-1', string $queryArg = '_wpnonce'): int|false
    {
        if (($GLOBALS['onesmtp_test_nonce_valid'] ?? true) === false) {
            wp_die('Invalid nonce.');
        }

        return 1;
    }
}

if (! function_exists('submit_button')) {
    function submit_button(string $text = '', string $type = 'primary', string $name = 'submit', bool $wrap = true, array|string $otherAttributes = ''): void
    {
        $button = '<input type="submit" name="' . esc_attr($name) . '" class="button ' . esc_attr($type) . '" value="' . esc_attr($text) . '">';
        echo $wrap ? '<p class="submit">' . $button . '</p>' : $button;
    }
}

if (! function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.org/wp-admin/' . ltrim($path, '/');
    }
}

if (! function_exists('add_query_arg')) {
    function add_query_arg(array|string $key, mixed $value = null, string|false $url = false): string
    {
        $args = is_array($key) ? $key : [$key => $value];
        $base = is_string($url) && $url !== '' ? $url : 'https://example.org/wp-admin/admin.php';
        $fragment = '';

        if (str_contains($base, '#')) {
            [$base, $fragment] = explode('#', $base, 2);
            $fragment = '#' . $fragment;
        }

        $separator = str_contains($base, '?') ? '&' : '?';

        return $base . $separator . http_build_query($args) . $fragment;
    }
}

if (! function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location, int $status = 302, string $xRedirectBy = 'WordPress'): bool
    {
        $GLOBALS['onesmtp_test_redirect'] = [
            'location' => $location,
            'status' => $status,
            'x_redirect_by' => $xRedirectBy,
        ];

        return true;
    }
}

if (! function_exists('wp_die')) {
    function wp_die(string $message = '', string $title = '', array|string|int $args = []): void
    {
        $GLOBALS['onesmtp_test_wp_die'] = [
            'message' => $message,
            'title' => $title,
            'args' => $args,
        ];

        throw new RuntimeException($message);
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

if (! function_exists('wp_cache_get')) {
    function wp_cache_get(string $key, string $group = '', bool $force = false, mixed &$found = null): mixed
    {
        $index = $group . ':' . $key;
        $found = array_key_exists($index, $GLOBALS['onesmtp_test_object_cache'] ?? []);

        return $found ? $GLOBALS['onesmtp_test_object_cache'][$index] : false;
    }
}

if (! function_exists('wp_cache_set')) {
    function wp_cache_set(string $key, mixed $data, string $group = '', int $expire = 0): bool
    {
        if (! isset($GLOBALS['onesmtp_test_object_cache'])) {
            $GLOBALS['onesmtp_test_object_cache'] = [];
        }

        $GLOBALS['onesmtp_test_object_cache'][$group . ':' . $key] = $data;

        return true;
    }
}

if (! function_exists('wp_cache_delete')) {
    function wp_cache_delete(string $key, string $group = ''): bool
    {
        unset($GLOBALS['onesmtp_test_object_cache'][$group . ':' . $key]);

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
