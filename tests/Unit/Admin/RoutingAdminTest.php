<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Admin;

use OneSMTP\Admin\RoutingAdmin;
use OneSMTP\Dispatch\RoutingRulesRepository;
use OneSMTP\Product\FeatureGate;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RoutingAdminTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['onesmtp_test_options'] = [];
        $GLOBALS['onesmtp_test_object_cache'] = [];
        $GLOBALS['onesmtp_test_fired_actions'] = [];
        $GLOBALS['onesmtp_test_current_user_can'] = true;
        $GLOBALS['pagenow'] = 'options-general.php';
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function test_free_installation_renders_disabled_state_without_rule_form(): void
    {
        ob_start();
        (new RoutingAdmin())->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Available with Pro', $html);
        self::assertStringContainsString('Requires Pro', $html);
        self::assertStringNotContainsString('name="condition_value"', $html);
    }

    public function test_enabled_feature_renders_empty_state_and_bounded_form(): void
    {
        $gate = new FeatureGate([FeatureGate::SMART_ROUTING => true], true);

        ob_start();
        (new RoutingAdmin(featureGate: $gate))->render([
            [
				'id' => 5,
				'name' => 'Fixture SMTP',
			],
        ]);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('No conditional routing rules are configured.', $html);
        self::assertStringContainsString('name="condition_value"', $html);
        self::assertStringContainsString('maxlength="500"', $html);
        self::assertStringContainsString('first 4096 characters', $html);
    }

    public function test_enabled_save_persists_a_rule_without_message_content_or_audit_payload(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->activeProviders = [
            [
				'id' => 5,
				'name' => 'Fixture SMTP',
				'is_active' => 1,
			],
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_routing_action' => 'save',
            'provider_id' => '5',
            'priority' => '10',
            'condition_field' => 'content',
            'condition_operator' => 'contains',
            'condition_value' => 'fixture phrase',
            'enabled' => '1',
            'onesmtp_routing_nonce' => 'test-nonce',
        ];

        $admin = new RoutingAdmin(
            new RoutingRulesRepository(),
            new ProviderRepository(),
            new FeatureGate([FeatureGate::SMART_ROUTING => true], true)
        );

        try {
            $admin->handleRequest();
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('redirected', $exception->getMessage());
        }

        $rules = (new RoutingRulesRepository())->get();
        self::assertSame('content', $rules[0]['conditions'][0]['field'] ?? null);
        self::assertSame('fixture phrase', $rules[0]['conditions'][0]['value'] ?? null);
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Test-only assertion over an in-memory fixture.
        self::assertStringNotContainsString('fixture phrase', serialize($GLOBALS['onesmtp_test_fired_actions'] ?? []));
        self::assertSame('audit_routing_rule_changed', $GLOBALS['wpdb']->inserts[0]['data']['event_type'] ?? null);
        self::assertStringNotContainsString('fixture phrase', (string) ($GLOBALS['wpdb']->inserts[0]['data']['context_json'] ?? ''));
    }

    public function test_enabled_update_records_metadata_only_audit_event(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->activeProviders = [
            [
				'id' => 5,
				'name' => 'Fixture SMTP',
				'is_active' => 1,
			],
            [
				'id' => 6,
				'name' => 'Backup SMTP',
				'is_active' => 1,
			],
        ];
        $repository = new RoutingRulesRepository();
        $repository->add([
            'provider_id' => 5,
            'priority' => 10,
            'conditions' => [
				[
					'field' => 'content',
					'operator' => 'contains',
					'value' => 'old phrase',
				],
			],
        ]);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_routing_action' => 'update',
            'rule_id' => '1',
            'provider_id' => '6',
            'priority' => '2',
            'condition_field' => 'subject',
            'condition_operator' => 'contains',
            'condition_value' => 'new phrase',
            'enabled' => '1',
            'onesmtp_routing_nonce' => 'test-nonce',
        ];

        try {
            (new RoutingAdmin(
                $repository,
                new ProviderRepository(),
                new FeatureGate([FeatureGate::SMART_ROUTING => true], true)
            ))->handleRequest();
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('redirected', $exception->getMessage());
        }

        $updated = $repository->get();
        self::assertSame('subject', $updated[0]['conditions'][0]['field'] ?? null);
        self::assertSame('new phrase', $updated[0]['conditions'][0]['value'] ?? null);
        $context = (string) ($GLOBALS['wpdb']->inserts[0]['data']['context_json'] ?? '');
        self::assertStringContainsString('updated', $context);
        self::assertStringNotContainsString('new phrase', $context);
    }

    public function test_enabled_delete_records_metadata_only_audit_event(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['wpdb']->activeProviders = [
            [
				'id' => 5,
				'name' => 'Fixture SMTP',
				'is_active' => 1,
			],
        ];
        $repository = new RoutingRulesRepository();
        $repository->add([
            'provider_id' => 5,
            'conditions' => [
				[
					'field' => 'content',
					'operator' => 'contains',
					'value' => 'delete phrase',
				],
			],
        ]);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'onesmtp_routing_action' => 'delete',
            'rule_id' => '1',
            'onesmtp_routing_nonce' => 'test-nonce',
        ];

        try {
            (new RoutingAdmin(
                $repository,
                new ProviderRepository(),
                new FeatureGate([FeatureGate::SMART_ROUTING => true], true)
            ))->handleRequest();
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('redirected', $exception->getMessage());
        }

        self::assertSame([], $repository->get());
        $context = (string) ($GLOBALS['wpdb']->inserts[0]['data']['context_json'] ?? '');
        self::assertStringContainsString('deleted', $context);
        self::assertStringNotContainsString('delete phrase', $context);
    }
}
