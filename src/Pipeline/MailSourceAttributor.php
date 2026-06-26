<?php

declare(strict_types=1);

namespace OneSMTP\Pipeline;

final class MailSourceAttributor
{
    public const PAYLOAD_KEY = 'onesmtp_source';

    private const TRACE_LIMIT = 18;
    private const MAX_VALUE_LENGTH = 120;

    /**
     * @param array<string,mixed> $mailArgs
     * @return array<string,mixed>
     */
    public function withSource(array $mailArgs): array
    {
        if (isset($mailArgs[self::PAYLOAD_KEY]) && is_array($mailArgs[self::PAYLOAD_KEY])) {
            $mailArgs[self::PAYLOAD_KEY] = $this->normalize($mailArgs[self::PAYLOAD_KEY]);

            return $mailArgs;
        }

        $mailArgs[self::PAYLOAD_KEY] = $this->detectFromFrames(
            debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, self::TRACE_LIMIT)
        );

        return $mailArgs;
    }

    /**
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    public function normalize(array $source): array
    {
        $normalized = [];
        foreach (['type', 'fixture', 'origin', 'name', 'slug'] as $key) {
            if (! isset($source[$key]) || ! is_scalar($source[$key])) {
                continue;
            }

            $value = $key === 'type' || $key === 'slug'
                ? sanitize_key((string) $source[$key])
                : $this->sanitizeLabel((string) $source[$key]);

            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        if (isset($source['metadata']) && is_array($source['metadata'])) {
            $metadata = [];
            foreach ($source['metadata'] as $key => $value) {
                if (! is_scalar($value)) {
                    continue;
                }

                $safeKey = sanitize_key((string) $key);
                $safeValue = $this->sanitizeLabel((string) $value);
                if ($safeKey !== '' && $safeValue !== '') {
                    $metadata[$safeKey] = $safeValue;
                }
            }

            $normalized['metadata'] = $metadata;
        }

        if (! isset($normalized['type'])) {
            $normalized['type'] = 'unknown';
        }

        return $normalized;
    }

    /**
     * @param array<int,array<string,mixed>> $frames
     * @return array{type:string,name:string,slug:string,origin:string}
     */
    public function detectFromFrames(array $frames): array
    {
        $coreSource = null;

        foreach ($frames as $frame) {
            $file = isset($frame['file']) && is_string($frame['file']) ? $this->normalizePath($frame['file']) : '';
            if ($file === '' || $this->isOwnFile($file)) {
                continue;
            }

            $pluginSource = $this->sourceFromPluginPath($file, $this->pluginDir());
            if ($pluginSource !== null) {
                return $pluginSource;
            }

            $muPluginSource = $this->sourceFromPluginPath($file, $this->muPluginDir());
            if ($muPluginSource !== null) {
                return $muPluginSource;
            }

            $themeSource = $this->sourceFromThemePath($file, $this->themeDir());
            if ($themeSource !== null) {
                return $themeSource;
            }

            if ($this->isCorePath($file)) {
                $coreSource = [
                    'type' => 'core',
                    'name' => 'WordPress core',
                    'slug' => 'wordpress',
                    'origin' => 'detected',
                ];
            }
        }

        if ($coreSource !== null) {
            return $coreSource;
        }

        return [
            'type' => 'unknown',
            'name' => 'Unknown WordPress source',
            'slug' => 'unknown',
            'origin' => 'detected',
        ];
    }

    private function sourceFromPluginPath(string $file, string $baseDir): ?array
    {
        if ($baseDir === '' || ! str_starts_with($file, $baseDir . '/')) {
            return null;
        }

        $relative = ltrim(substr($file, strlen($baseDir)), '/');
        $firstSegment = strtok($relative, '/');
        if (! is_string($firstSegment)) {
            return null;
        }

        $slug = sanitize_key(preg_replace('/\.php$/', '', $firstSegment) ?? $firstSegment);
        if ($slug === '') {
            return null;
        }

        return [
            'type' => 'plugin',
            'name' => $this->labelFromSlug($slug),
            'slug' => $slug,
            'origin' => 'detected',
        ];
    }

    private function sourceFromThemePath(string $file, string $baseDir): ?array
    {
        if ($baseDir === '' || ! str_starts_with($file, $baseDir . '/')) {
            return null;
        }

        $relative = ltrim(substr($file, strlen($baseDir)), '/');
        $firstSegment = strtok($relative, '/');
        if (! is_string($firstSegment)) {
            return null;
        }

        $slug = sanitize_key($firstSegment);
        if ($slug === '') {
            return null;
        }

        return [
            'type' => 'theme',
            'name' => $this->labelFromSlug($slug),
            'slug' => $slug,
            'origin' => 'detected',
        ];
    }

    private function isCorePath(string $file): bool
    {
        $root = defined('ABSPATH') ? $this->normalizePath((string) constant('ABSPATH')) : '';
        if ($root === '') {
            return str_contains($file, '/wp-admin/') || str_contains($file, '/wp-includes/');
        }

        $root = rtrim($root, '/');

        return str_starts_with($file, $root . '/wp-admin/')
            || str_starts_with($file, $root . '/wp-includes/')
            || $file === $root . '/wp-load.php'
            || $file === $root . '/wp-mail.php';
    }

    private function isOwnFile(string $file): bool
    {
        $ownRoot = $this->normalizePath(dirname(__DIR__, 2));

        return $ownRoot !== '' && str_starts_with($file, rtrim($ownRoot, '/') . '/');
    }

    private function pluginDir(): string
    {
        if (defined('WP_PLUGIN_DIR')) {
            return rtrim($this->normalizePath((string) constant('WP_PLUGIN_DIR')), '/');
        }

        if (defined('WP_CONTENT_DIR')) {
            return rtrim($this->normalizePath((string) constant('WP_CONTENT_DIR')), '/') . '/plugins';
        }

        return '';
    }

    private function muPluginDir(): string
    {
        if (defined('WPMU_PLUGIN_DIR')) {
            return rtrim($this->normalizePath((string) constant('WPMU_PLUGIN_DIR')), '/');
        }

        if (defined('WP_CONTENT_DIR')) {
            return rtrim($this->normalizePath((string) constant('WP_CONTENT_DIR')), '/') . '/mu-plugins';
        }

        return '';
    }

    private function themeDir(): string
    {
        if (function_exists('get_theme_root')) {
            $root = get_theme_root();
            if (is_string($root) && $root !== '') {
                return rtrim($this->normalizePath($root), '/');
            }
        }

        if (defined('WP_CONTENT_DIR')) {
            return rtrim($this->normalizePath((string) constant('WP_CONTENT_DIR')), '/') . '/themes';
        }

        return '';
    }

    private function labelFromSlug(string $slug): string
    {
        $label = str_replace(['-', '_'], ' ', $slug);
        $label = ucwords($label);

        return $this->sanitizeLabel($label);
    }

    private function sanitizeLabel(string $value): string
    {
        $value = sanitize_text_field($value);
        $value = preg_replace('/[\/\\\\]+/', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        if (strlen($value) <= self::MAX_VALUE_LENGTH) {
            return $value;
        }

        return rtrim(substr($value, 0, self::MAX_VALUE_LENGTH - 3)) . '...';
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
