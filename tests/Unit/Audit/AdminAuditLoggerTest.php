<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Audit;

use OneSMTP\Audit\AdminAuditLogger;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class AdminAuditLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    public function test_provider_audit_redacts_sensitive_context_and_free_text(): void
    {
        $logger = new AdminAuditLogger();

        $logger->logProviderChange('created', 7, [
            'summary' => 'Created provider with token=super-secret',
            'provider_name' => 'Primary SMTP',
            'safe_config_fields' => ['host'],
            'credential_fields_updated' => ['password', 'api_key'],
            'raw_context' => [
                'password' => 'secret-password',
                'authorization' => 'Bearer provider-token',
            ],
        ]);

        self::assertCount(1, $GLOBALS['wpdb']->inserts);
        self::assertSame('audit_provider_changed', $GLOBALS['wpdb']->inserts[0]['data']['event_type']);

        $json = (string) $GLOBALS['wpdb']->inserts[0]['data']['context_json'];
        self::assertStringContainsString('[REDACTED]', $json);
        self::assertStringNotContainsString('super-secret', $json);
        self::assertStringNotContainsString('secret-password', $json);
        self::assertStringNotContainsString('provider-token', $json);
    }

    public function test_routing_rule_audit_contains_metadata_only(): void
    {
        $logger = new AdminAuditLogger();

        $logger->logRoutingRuleChange('created', 3, 7, 10, 1, true);

        self::assertCount(1, $GLOBALS['wpdb']->inserts);
        self::assertSame('audit_routing_rule_changed', $GLOBALS['wpdb']->inserts[0]['data']['event_type']);

        $json = (string) $GLOBALS['wpdb']->inserts[0]['data']['context_json'];
        self::assertStringContainsString('condition_count', $json);
        self::assertStringNotContainsString('message', $json);
        self::assertStringNotContainsString('recipient', $json);
        self::assertStringNotContainsString('content', $json);
    }
}
