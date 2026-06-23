<?php

declare(strict_types=1);

namespace OneSMTP\Conflict;

final class MailConflictDetector implements MailConflictDetectorInterface
{
    /**
     * @var array<string,string>
     */
    private const KNOWN_MAIL_PLUGINS = [
        'easy-wp-smtp/easy-wp-smtp.php' => 'Easy WP SMTP',
        'fluent-smtp/fluent-smtp.php' => 'FluentSMTP',
        'post-smtp/postman-smtp.php' => 'Post SMTP',
        'smtp-mailer/main.php' => 'SMTP Mailer',
        'wp-mail-smtp/wp_mail_smtp.php' => 'WP Mail SMTP',
        'wp-smtp/wp-smtp.php' => 'WP SMTP',
    ];

    /**
     * @var string[]
     */
    private const MAIL_HOOKS = [
        'pre_wp_mail',
        'wp_mail',
        'wp_mail_from',
        'wp_mail_from_name',
        'wp_mail_content_type',
        'phpmailer_init',
    ];

    /**
     * @return array{plugins:list<string>,hooks:array<string,int>}
     */
    public function detect(): array
    {
        return [
            'plugins' => $this->detectActivePlugins(),
            'hooks' => $this->detectMailHooks(),
        ];
    }

    /**
     * @return list<string>
     */
    private function detectActivePlugins(): array
    {
        $active = get_option('active_plugins', []);
        if (! is_array($active)) {
            $active = [];
        }

        $sitewide = function_exists('get_site_option') ? get_site_option('active_sitewide_plugins', []) : [];
        if (is_array($sitewide)) {
            $active = array_merge($active, array_keys($sitewide));
        }

        $plugins = [];
        foreach ($active as $pluginFile) {
            if (! is_string($pluginFile)) {
                continue;
            }

            if (isset(self::KNOWN_MAIL_PLUGINS[$pluginFile])) {
                $plugins[] = self::KNOWN_MAIL_PLUGINS[$pluginFile];
            }
        }

        $plugins = array_values(array_unique($plugins));
        sort($plugins);

        return $plugins;
    }

    /**
     * @return array<string,int>
     */
    private function detectMailHooks(): array
    {
        $hooks = [];
        foreach (self::MAIL_HOOKS as $hookName) {
            $count = $this->countCallbacks($hookName);
            if ($count > 0) {
                $hooks[$hookName] = $count;
            }
        }

        return $hooks;
    }

    private function countCallbacks(string $hookName): int
    {
        global $wp_filter;

        if (! isset($wp_filter[$hookName])) {
            return 0;
        }

        $hook = $wp_filter[$hookName];
        if (is_object($hook) && isset($hook->callbacks) && is_array($hook->callbacks)) {
            return $this->countCallbackGroups($hook->callbacks);
        }

        if (is_array($hook)) {
            return $this->countCallbackGroups($hook);
        }

        return 0;
    }

    /**
     * @param array<mixed> $groups
     */
    private function countCallbackGroups(array $groups): int
    {
        $count = 0;
        foreach ($groups as $callbacks) {
            if (! is_array($callbacks)) {
                continue;
            }

            foreach ($callbacks as $callback) {
                $callable = is_array($callback) && array_key_exists('function', $callback) ? $callback['function'] : $callback;
                if ($this->isOneSmtpCallback($callable)) {
                    continue;
                }

                $count++;
            }
        }

        return $count;
    }

    private function isOneSmtpCallback(mixed $callback): bool
    {
        if (is_string($callback)) {
            return str_starts_with(ltrim($callback, '\\'), 'OneSMTP\\');
        }

        if (is_array($callback) && isset($callback[0])) {
            $class = is_object($callback[0]) ? $callback[0]::class : (string) $callback[0];

            return str_starts_with(ltrim($class, '\\'), 'OneSMTP\\');
        }

        if ($callback instanceof \Closure) {
            return false;
        }

        if (is_object($callback)) {
            return str_starts_with(ltrim($callback::class, '\\'), 'OneSMTP\\');
        }

        return false;
    }
}
