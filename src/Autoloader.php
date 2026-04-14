<?php

declare(strict_types=1);

namespace OneSMTP;

final class Autoloader
{
    private const PREFIX = 'OneSMTP\\';

    public static function register(): void
    {
        spl_autoload_register([self::class, 'autoload']);
    }

    private static function autoload(string $class): void
    {
        if (strpos($class, self::PREFIX) !== 0) {
            return;
        }

        $relative = substr($class, strlen(self::PREFIX));
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
        $file     = ONESMTP_PATH . 'src/' . $relative . '.php';

        if (is_readable($file)) {
            require_once $file;
        }
    }
}
