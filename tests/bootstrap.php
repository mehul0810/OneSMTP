<?php

declare(strict_types=1);

/**
 * Shared bootstrap for OneSMTP tests.
 *
 * Loads Composer autoload when available. If dependencies are not installed yet,
 * syntax-only checks still work and PHPUnit will fail fast with a clear message.
 */
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];

foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        return;
    }
}

fwrite(
    STDERR,
    "[OneSMTP tests] Composer autoload not found. Run 'npm ci' and 'composer install' (or at minimum 'composer install') before executing PHPUnit.\n"
);
