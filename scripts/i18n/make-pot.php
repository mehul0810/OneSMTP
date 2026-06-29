<?php
/**
 * Generate the OneSMTP POT file from translatable PHP strings.
 *
 * This small generator keeps the release gate runnable without requiring WP-CLI
 * in every local or CI environment. It intentionally handles literal strings
 * passed to common WordPress translation functions.
 *
 * @package OneSMTP
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$domain = 'onesmtp';
$output = $root . '/languages/onesmtp.pot';
$functions = [
    '__',
    '_e',
    '_x',
    '_n',
    '_nx',
    'esc_html__',
    'esc_html_e',
    'esc_attr__',
    'esc_attr_e',
];

$strings = [];
$files = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file): bool {
            $path = str_replace('\\', '/', $file->getPathname());

            foreach (['/.git/', '/vendor/', '/node_modules/', '/build/', '/tests/'] as $excluded) {
                if (str_contains($path, $excluded)) {
                    return false;
                }
            }

            return $file->isDir() || str_ends_with($path, '.php');
        }
    )
);

foreach ($files as $file) {
    if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }

    $relativePath = ltrim(str_replace($root, '', $file->getPathname()), '/');
    $tokens = token_get_all((string) file_get_contents($file->getPathname()));
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], $functions, true)) {
            continue;
        }

        $function = $token[1];
        $line = (int) $token[2];
        $cursor = onesmtp_i18n_next_non_whitespace($tokens, $i + 1);

        if (($tokens[$cursor] ?? null) !== '(') {
            continue;
        }

        $arguments = onesmtp_i18n_collect_literal_arguments($tokens, $cursor + 1);

        if ($arguments === []) {
            continue;
        }

        if (! onesmtp_i18n_uses_text_domain($function, $arguments, $domain)) {
            continue;
        }

        $message = onesmtp_i18n_first_message_argument($function, $arguments);

        if ($message === null || $message === '') {
            continue;
        }

        $strings[$message]['refs'][] = $relativePath . ':' . $line;
    }
}

ksort($strings);

if (! is_dir(dirname($output))) {
    mkdir(dirname($output), 0755, true);
}

file_put_contents($output, onesmtp_i18n_render_pot($strings));

echo sprintf("Generated %s with %d string(s).\n", str_replace($root . '/', '', $output), count($strings));

/**
 * Find the next non-whitespace token index.
 *
 * @param array<int, mixed> $tokens Token stream.
 * @param int              $start  Start index.
 */
function onesmtp_i18n_next_non_whitespace(array $tokens, int $start): int
{
    $count = count($tokens);

    for ($i = $start; $i < $count; $i++) {
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_WHITESPACE) {
            continue;
        }

        return $i;
    }

    return $count;
}

/**
 * Collect top-level literal string arguments from a function call.
 *
 * @param array<int, mixed> $tokens Token stream.
 * @param int              $start  First token after opening parenthesis.
 *
 * @return array<int, string|null>
 */
function onesmtp_i18n_collect_literal_arguments(array $tokens, int $start): array
{
    $arguments = [];
    $current = null;
    $depth = 1;
    $count = count($tokens);

    for ($i = $start; $i < $count; $i++) {
        $token = $tokens[$i];

        if ($token === '(' || $token === '[') {
            $depth++;
            continue;
        }

        if ($token === ')' || $token === ']') {
            $depth--;

            if ($depth === 0) {
                $arguments[] = $current;
                break;
            }

            continue;
        }

        if ($depth === 1 && $token === ',') {
            $arguments[] = $current;
            $current = null;
            continue;
        }

        if ($depth !== 1 || ! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $literal = onesmtp_i18n_decode_php_string($token[1]);
        $current = $current === null ? $literal : $current . $literal;
    }

    return $arguments;
}

/**
 * Check whether the collected arguments use the expected text domain.
 *
 * @param array<int, string|null> $arguments Literal arguments.
 */
function onesmtp_i18n_uses_text_domain(string $function, array $arguments, string $domain): bool
{
    $domainIndex = match ($function) {
        '_x' => 2,
        '_n' => 3,
        '_nx' => 4,
        default => 1,
    };

    return ($arguments[$domainIndex] ?? null) === $domain;
}

/**
 * Return the message argument for a translation function.
 *
 * @param array<int, string|null> $arguments Literal arguments.
 */
function onesmtp_i18n_first_message_argument(string $function, array $arguments): ?string
{
    return match ($function) {
        '_n', '_nx' => $arguments[0] ?? null,
        default => $arguments[0] ?? null,
    };
}

/**
 * Decode a PHP string literal without evaluating code.
 *
 * @param string $literal PHP string literal token.
 */
function onesmtp_i18n_decode_php_string(string $literal): string
{
    return (string) stripcslashes(substr($literal, 1, -1));
}

/**
 * Render a POT file.
 *
 * @param array<string, array{refs: array<int, string>}> $strings Extracted strings.
 */
function onesmtp_i18n_render_pot(array $strings): string
{
    $year = gmdate('Y');
    $date = gmdate('Y-m-d H:iO');
    $output = <<<POT
# Copyright (C) {$year} OneSMTP
# This file is distributed under the same license as the OneSMTP plugin.
msgid ""
msgstr ""
"Project-Id-Version: OneSMTP 0.1.0\\n"
"Report-Msgid-Bugs-To: https://github.com/mehul0810/OneSMTP/issues\\n"
"POT-Creation-Date: {$date}\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"X-Domain: onesmtp\\n"

POT;

    foreach ($strings as $message => $meta) {
        foreach (array_unique($meta['refs']) as $reference) {
            $output .= '#: ' . $reference . PHP_EOL;
        }

        $output .= 'msgid "' . onesmtp_i18n_escape_pot($message) . '"' . PHP_EOL;
        $output .= 'msgstr ""' . PHP_EOL . PHP_EOL;
    }

    return rtrim($output) . PHP_EOL;
}

/**
 * Escape a string for a single-line POT msgid.
 *
 * @param string $value Raw string.
 */
function onesmtp_i18n_escape_pot(string $value): string
{
    return str_replace(
        ["\\", "\"", "\n", "\r", "\t"],
        ["\\\\", "\\\"", "\\n", "\\r", "\\t"],
        $value
    );
}
