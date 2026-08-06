<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    define('ABSPATH', '/tmp/onesmtp-wordpress/');
}

if (! defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

if (! function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        unset($domain);
        return $number === 1 ? $single : $plural;
    }
}

if (! function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text, bool $removeBreaks = false): string
    {
        unset($removeBreaks);
        return strip_tags($text);
    }
}
