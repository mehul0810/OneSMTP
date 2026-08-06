<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Migration;

use OneSMTP\Migration\SureMailMigrationService;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class SureMailMigrationServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_options'] = [];
    }

    public function test_analysis_and_import_use_only_the_default_connection_and_reencrypt_its_secret(): void
    {
        $source = [
            'connections' => [
                'default-id' => [
                    'id' => 'default-id',
                    'type' => 'EMAILIT',
                    'connection_title' => 'SureMail Emailit',
                    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Fixture mirrors SureMail's stored credential encoding.
                    'api_key' => rtrim(base64_encode('source-secret'), '='),
                    'from_email' => 'sender@example.test',
                    'priority' => 10,
                ],
                'ignored-id' => [
					'id' => 'ignored-id',
					'type' => 'SMTP',
					'host' => 'ignored.example.test',
				],
            ],
            'default_connection' => [
				'id' => 'default-id',
				'type' => 'EMAILIT',
				'connection_title' => 'SureMail Emailit',
			],
            'email_simulation' => 'yes',
        ];
        update_option('suremails_connections', $source, false);

        $service = new SureMailMigrationService(new ProviderRepository());
        $analysis = $service->analyze();

        self::assertTrue($analysis['supported']);
        self::assertSame('emailit', $analysis['target_type']);
        self::assertSame(1, $analysis['skipped_connections']);

        $result = $service->import( (string) $analysis['fingerprint']);
        self::assertTrue($result['ok']);
        self::assertCount(1, $GLOBALS['wpdb']->inserts);

        $stored = json_decode( (string) $GLOBALS['wpdb']->inserts[0]['data']['config_json'], true);
        self::assertStringStartsWith('onesmtp:v1:gcm:', (string) ($stored['api_key'] ?? ''));
        self::assertStringNotContainsString('source-secret', (string) $GLOBALS['wpdb']->inserts[0]['data']['config_json']);
        self::assertSame(0, $GLOBALS['wpdb']->inserts[0]['data']['is_active']);
        self::assertSame($source, get_option('suremails_connections'));
    }

    public function test_analysis_blocks_import_when_required_secret_cannot_be_decoded(): void
    {
        update_option('suremails_connections', [
            'connections' => [
                'default-id' => [
                    'id' => 'default-id',
                    'type' => 'EMAILIT',
                    'connection_title' => 'Broken Emailit',
                    'api_key' => '%%%not-base64%%%',
                ],
            ],
            'default_connection' => [
				'id' => 'default-id',
				'type' => 'EMAILIT',
			],
        ], false);

        $service = new SureMailMigrationService(new ProviderRepository());
        $analysis = $service->analyze();

        self::assertTrue($analysis['supported']);
        self::assertFalse($analysis['importable']);
        self::assertSame(['api_key'], $analysis['missing_fields']);
        self::assertFalse($service->import( (string) $analysis['fingerprint'])['ok']);
        self::assertSame([], $GLOBALS['wpdb']->inserts);
    }

    public function test_postmark_server_token_is_mapped_and_reencrypted_as_api_key(): void
    {
        update_option('suremails_connections', [
            'connections' => [
                'postmark-id' => [
                    'id' => 'postmark-id',
                    'type' => 'POSTMARK',
                    'connection_title' => 'Postmark',
                    // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Fixture mirrors SureMail's stored credential encoding.
                    'server_token' => rtrim(base64_encode('server-secret'), '='),
                ],
            ],
            'default_connection' => [
				'id' => 'postmark-id',
				'type' => 'POSTMARK',
			],
        ], false);

        $service = new SureMailMigrationService(new ProviderRepository());
        $analysis = $service->analyze();
        self::assertTrue($analysis['importable']);
        self::assertTrue($service->import( (string) $analysis['fingerprint'])['ok']);

        $stored = json_decode( (string) $GLOBALS['wpdb']->inserts[0]['data']['config_json'], true);
        self::assertStringStartsWith('onesmtp:v1:gcm:', (string) ($stored['api_key'] ?? ''));
    }
}
