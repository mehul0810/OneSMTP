<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Pipeline;

use OneSMTP\Pipeline\MailSourceAttributor;
use PHPUnit\Framework\TestCase;

final class MailSourceAttributorTest extends TestCase
{
    public function test_normalize_keeps_safe_fixture_source_and_removes_unsafe_values(): void
    {
        $attributor = new MailSourceAttributor();

        $source = $attributor->normalize(
            [
                'type' => 'Plugin Source!',
                'name' => 'Checkout <b>Mailer</b> /srv/site/private.php',
                'slug' => 'Checkout Mailer!',
                'origin' => 'detected',
                'fixture' => 'fixture-123',
                'metadata' => [
                    'object_id' => 'order-1001',
                    'token' => ['secret'],
                ],
                'payload_json' => '{"message":"secret"}',
            ]
        );

        self::assertSame('unknown', $source['type']);
        self::assertSame('Checkout Mailer srv site private.php', $source['name']);
        self::assertSame('checkoutmailer', $source['slug']);
        self::assertSame('detected', $source['origin']);
        self::assertSame('fixture-123', $source['fixture']);
        self::assertSame(['object_id' => 'order-1001'], $source['metadata']);
        self::assertArrayNotHasKey('payload_json', $source);
    }

    public function test_detect_from_frames_prefers_plugin_theme_core_then_unknown_without_paths(): void
    {
        $attributor = new MailSourceAttributor();

        if (! defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', '/var/www/wp-content');
        }

        if (! defined('ABSPATH')) {
            define('ABSPATH', '/var/www/');
        }

        $plugin = $attributor->detectFromFrames([
            ['file' => '/var/www/wp-includes/class-wp-hook.php'],
            ['file' => '/var/www/wp-content/plugins/contact-form-pro/includes/mail.php'],
        ]);
        self::assertSame('plugin', $plugin['type']);
        self::assertSame('Contact Form Pro', $plugin['name']);
        self::assertSame('contact-form-pro', $plugin['slug']);

        $theme = $attributor->detectFromFrames([
            ['file' => '/var/www/wp-content/themes/child-theme/functions.php'],
        ]);
        self::assertSame('theme', $theme['type']);
        self::assertSame('Child Theme', $theme['name']);
        self::assertSame('child-theme', $theme['slug']);

        $core = $attributor->detectFromFrames([
            ['file' => '/var/www/wp-includes/pluggable.php'],
        ]);
        self::assertSame('core', $core['type']);
        self::assertSame('WordPress core', $core['name']);

        $unknown = $attributor->detectFromFrames([
            ['file' => '/tmp/custom-mailer.php'],
        ]);
        self::assertSame('unknown', $unknown['type']);
        self::assertStringNotContainsString('/tmp', wp_json_encode($unknown));
    }

    public function test_with_source_adds_detected_unknown_source_for_unattributed_mail(): void
    {
        $payload = (new MailSourceAttributor())->withSource(
            [
                'to' => ['person@example.test'],
                'subject' => 'Example',
                'message' => 'Body',
            ]
        );

        self::assertArrayHasKey('onesmtp_source', $payload);
        self::assertSame('unknown', $payload['onesmtp_source']['type'] ?? null);
    }
}
