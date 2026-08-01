<?php

declare(strict_types=1);

namespace OneSMTP\Admin;

/**
 * Small PHP renderer for the Heroicons outline paths used by the admin shell.
 * Keeping the paths local avoids a second icon runtime in PHP-rendered screens.
 */
final class Heroicons
{
    /** @var array<string,string> */
    private const PATHS = [
        'envelope' => '<path d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25H4.5a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0l-7.5-4.615A2.25 2.25 0 0 1 2.25 6.993V6.75"/>',
        'squares' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3.75h6.5v6.5h-6.5zM13.75 3.75h6.5v6.5h-6.5zM3.75 13.75h6.5v6.5h-6.5zM13.75 13.75h6.5v6.5h-6.5z"/>',
        'user' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6.75a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.25a7.5 7.5 0 0 1 15 0"/>',
        'paper-airplane' => '<path stroke-linecap="round" stroke-linejoin="round" d="m6.115 5.19 12.69 3.06a1.5 1.5 0 0 1 0 2.91l-12.69 3.06a1.5 1.5 0 0 1-1.768-1.91l1.05-2.97a1.5 1.5 0 0 0 0-1l-1.05-2.97a1.5 1.5 0 0 1 1.768-1.91Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 10.5h9"/>',
        'inbox' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 13.5h4.386a2.25 2.25 0 0 1 2.012 1.243l.704 1.414a2.25 2.25 0 0 0 2.012 1.243h1.272a2.25 2.25 0 0 0 2.012-1.243l.704-1.414a2.25 2.25 0 0 1 2.012-1.243h4.386M3.75 4.5h16.5l1.5 9v4.5a2.25 2.25 0 0 1-2.25 2.25H4.5A2.25 2.25 0 0 1 2.25 18v-4.5l1.5-9Z"/>',
        'question' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519a3.75 3.75 0 1 1 6.242 3.963c-.782.68-1.621 1.23-2.121 2.268-.18.374-.27.79-.27 1.2M12 18.75h.008v.008H12v-.008Z"/><circle cx="12" cy="12" r="9.75"/>',
    ];

    public static function render(string $name, string $class = ''): string
    {
        $path = self::PATHS[$name] ?? '';
        if ($path === '') {
            return '';
        }

        return '<svg class="onesmtp-heroicon ' . esc_attr($class) . '" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor" aria-hidden="true" focusable="false">' . $path . '</svg>';
    }
}
