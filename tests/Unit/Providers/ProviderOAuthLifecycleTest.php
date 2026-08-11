<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Providers;

use OneSMTP\Product\FeatureGate;
use OneSMTP\Providers\Auth\ProviderOAuthHttpClientInterface;
use OneSMTP\Providers\Auth\ProviderOAuthHttpResponse;
use OneSMTP\Providers\Auth\ProviderOAuthLifecycleCoordinator;
use OneSMTP\Providers\Auth\ProviderOAuthStateStoreInterface;
use OneSMTP\Providers\Auth\WordPressProviderOAuthStateStore;
use OneSMTP\Providers\Adapters\GmailAdapter;
use OneSMTP\Providers\Adapters\SmtpAdapter;
use OneSMTP\Providers\Adapters\ZohoMailAdapter;
use OneSMTP\Providers\Adapters\ZohoOAuthTokenProvider;
use OneSMTP\Providers\Auth\ProviderOAuthTokenService;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\ProviderTypes;
use OneSMTP\Repository\ProviderRepository;
use OneSMTP\Tests\Support\FakeWpdb;
use PHPUnit\Framework\TestCase;

final class ProviderOAuthLifecycleTest extends TestCase
{
    private FakeOAuthHttp $http;
    private FakeOAuthStates $states;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['onesmtp_test_options'] = [];
        unset($GLOBALS['onesmtp_test_throw_on_update_option']);
        unset($GLOBALS['onesmtp_test_throw_on_get_option']);
        $GLOBALS['onesmtp_test_transients'] = [];
        $GLOBALS['onesmtp_test_remote_posts'] = [];
        $this->http = new FakeOAuthHttp();
        $this->states = new FakeOAuthStates();
    }

    public function test_google_start_uses_exact_send_scope_and_no_pkce(): void
    {
        $this->provider(7, ProviderTypes::GMAIL, [
            'client_id' => 'google-client',
            'client_secret' => 'google-secret',
        ]);
        $result = $this->coordinator()->begin(7, 'https://example.org/wp-admin/options-general.php?page=onesmtp');

        self::assertTrue($result['ok']);
        self::assertStringContainsString('gmail.send', (string) $result['authorization_url']);
        self::assertStringNotContainsString('code_challenge', (string) $result['authorization_url']);
        self::assertStringStartsWith('https://', (string) $result['callback_url']);
    }

    public function test_zoho_start_uses_regional_endpoint_and_s256_pkce(): void
    {
        $this->provider(8, ProviderTypes::ZOHO_MAIL, [
            'region' => 'eu',
            'account_id' => 'account-fixture',
            'client_id' => 'zoho-client',
            'client_secret' => 'zoho-secret',
        ]);
        $result = $this->coordinator()->begin(8);

        self::assertTrue($result['ok']);
        self::assertStringStartsWith('https://accounts.zoho.eu/oauth/v2/auth?', (string) $result['authorization_url']);
        self::assertStringContainsString('code_challenge_method=S256', (string) $result['authorization_url']);
        self::assertStringContainsString('ZohoMail.messages.CREATE', (string) $result['authorization_url']);
    }

    public function test_gate_off_is_default_deny_and_does_not_create_state(): void
    {
        $this->provider(7, ProviderTypes::GMAIL, [
			'client_id' => 'client',
			'client_secret' => 'secret',
		]);
        $result = (new ProviderOAuthLifecycleCoordinator(new ProviderRepository(), new FeatureGate()))->begin(7);

        self::assertFalse($result['ok']);
        self::assertSame('feature_unavailable', $result['code']);
        self::assertSame([], $this->states->records);
    }

    public function test_callback_binds_user_provider_type_scope_and_rejects_replay(): void
    {
        $this->provider(7, ProviderTypes::GMAIL, [
            'client_id' => 'google-client',
            'client_secret' => 'google-secret',
        ]);
        $coordinator = $this->coordinator();
        $start = $coordinator->begin(7);
        parse_str( (string) parse_url( (string) $start['authorization_url'], PHP_URL_QUERY), $query);
        $this->http->responses[] = new ProviderOAuthHttpResponse(200, [
            'access_token' => 'access-fixture',
            'refresh_token' => 'refresh-fixture',
            'expires_in' => 3600,
            'scope' => 'https://www.googleapis.com/auth/gmail.send',
        ]);

        $result = $coordinator->callback(7, [
			'state' => $query['state'],
			'code' => 'code-fixture',
		]);
        self::assertTrue($result['ok']);
        self::assertSame('connected', $result['code']);
        self::assertCount(1, $this->http->calls);
        self::assertSame('https://oauth2.googleapis.com/token', $this->http->calls[0]['url']);
        self::assertArrayNotHasKey('code_verifier', $this->http->calls[0]['body']);
        $savedConfig = json_decode( (string) ($GLOBALS['wpdb']->updates[0]['data']['config_json'] ?? ''), true);
        self::assertIsArray($savedConfig);
        self::assertArrayNotHasKey('access_token', $savedConfig);
        self::assertArrayNotHasKey('access_token_expires_at', $savedConfig);

        $replay = $coordinator->callback(7, [
			'state' => $query['state'],
			'code' => 'other-code',
		]);
        self::assertFalse($replay['ok']);
        self::assertSame('invalid_state', $replay['code']);
    }

    public function test_disconnect_cleans_local_credentials_even_when_remote_revoke_fails(): void
    {
        $this->provider(7, ProviderTypes::GMAIL, [
            'client_id' => 'google-client',
            'client_secret' => 'google-secret',
            'refresh_token' => 'refresh-fixture',
            'access_token' => 'access-fixture',
        ]);
        $this->http->responses[] = new ProviderOAuthHttpResponse(500, []);
        $result = $this->coordinator()->disconnect(7);

        self::assertTrue($result['ok']);
        self::assertSame('disconnected_remote_retry', $result['code']);
        self::assertSame('https://oauth2.googleapis.com/revoke', $this->http->calls[0]['url']);
        self::assertSame('access-fixture', $this->http->calls[0]['body']['token']);
        self::assertStringNotContainsString('access-fixture', $this->http->calls[0]['url']);
        self::assertSame(0, $GLOBALS['wpdb']->updates[0]['data']['is_active']);
    }

    public function test_disconnect_contains_remote_revoke_throwable_and_still_cleans_locally(): void
    {
        $this->provider(7, ProviderTypes::GMAIL, [
            'client_id' => 'google-client',
            'client_secret' => 'google-secret',
            'refresh_token' => 'refresh-fixture',
            'access_token' => 'access-fixture',
        ]);
        $this->http->throwOnPost = true;

        $result = $this->coordinator()->disconnect(7);

        self::assertTrue($result['ok']);
        self::assertSame('disconnected_remote_retry', $result['code']);
        self::assertSame(0, $GLOBALS['wpdb']->providerRowsById[7]['is_active']);
    }

    public function test_disconnect_persists_a_fail_closed_block_when_credential_rewrite_fails(): void
    {
        $provider = [
            'id' => 7,
            'slug' => 'provider-7',
            'name' => 'Gmail',
            'adapter_type' => ProviderTypes::GMAIL,
            'priority' => 1,
            'weight' => 1,
            'is_active' => 1,
            'circuit_state' => 'closed',
            'config_json' => wp_json_encode([
                'client_id' => 'google-client',
                'client_secret' => 'google-secret',
                'refresh_token' => 'refresh-fixture',
                'access_token' => 'access-fixture',
            ]),
        ];
        $GLOBALS['wpdb']->providerRowsById[7] = $provider;
        $GLOBALS['wpdb']->activeProviders = [ $provider ];
        $GLOBALS['wpdb']->failProviderConfigUpdates = true;
        $this->http->responses[] = new ProviderOAuthHttpResponse(200, []);

        $repository = new ProviderRepository();
        $result = $this->coordinator($repository)->disconnect(7);

        self::assertTrue($result['ok']);
        self::assertSame('disconnected_local_blocked', $result['code']);
        self::assertSame([], $repository->getActiveProviders());
        self::assertSame(0, (new ProviderRepository())->find(7)['is_active']);
        self::assertTrue( (bool) get_option('onesmtp_oauth_disconnect_blocked_7', false));
    }

    public function test_disconnect_blocks_when_atomic_active_update_fails(): void
    {
        $this->provider(7, ProviderTypes::GMAIL, [
            'client_id' => 'google-client',
            'client_secret' => 'google-secret',
            'refresh_token' => 'refresh-fixture',
            'access_token' => 'access-fixture',
        ]);
        $GLOBALS['wpdb']->providerRowsById[7]['is_active'] = 1;
        $GLOBALS['wpdb']->activeProviders = [ $GLOBALS['wpdb']->providerRowsById[7] ];
        $GLOBALS['wpdb']->failProviderActiveUpdates = true;

        $result = $this->coordinator()->disconnect(7);

        self::assertTrue($result['ok']);
        self::assertSame('disconnected_local_blocked', $result['code']);
        self::assertSame(1, $GLOBALS['wpdb']->providerRowsById[7]['is_active']);
        self::assertArrayHasKey('access_token', json_decode( (string) $GLOBALS['wpdb']->providerRowsById[7]['config_json'], true));
        self::assertSame([], (new ProviderRepository())->getActiveProviders());
    }

    public function test_disconnect_runtime_block_fails_closed_when_block_option_write_fails(): void
    {
        $this->provider(7, ProviderTypes::GMAIL, [
            'client_id' => 'google-client',
            'client_secret' => 'google-secret',
            'refresh_token' => 'refresh-fixture',
            'access_token' => 'access-fixture',
        ]);
        $GLOBALS['wpdb']->providerRowsById[7]['is_active'] = 1;
        $GLOBALS['wpdb']->activeProviders = [ $GLOBALS['wpdb']->providerRowsById[7] ];
        $GLOBALS['wpdb']->failProviderConfigUpdates = true;
        $GLOBALS['onesmtp_test_throw_on_update_option'] = 'onesmtp_oauth_disconnect_blocked_7';

        $repository = new ProviderRepository();
        $result = $this->coordinator($repository)->disconnect(7);

        self::assertFalse($result['ok']);
        self::assertSame('disconnect_blocked', $result['code']);
        self::assertSame([], $repository->getActiveProviders());
    }

    public function test_disconnect_returns_blocked_when_cleanup_and_block_marker_cannot_be_verified(): void
    {
        $this->provider(7, ProviderTypes::GMAIL, [
            'client_id' => 'google-client',
            'client_secret' => 'google-secret',
            'refresh_token' => 'refresh-fixture',
            'access_token' => 'access-fixture',
        ]);
        $GLOBALS['wpdb']->activeProviders = [ $GLOBALS['wpdb']->providerRowsById[7] ];
        $GLOBALS['wpdb']->failProviderConfigUpdates = true;
        $GLOBALS['onesmtp_test_throw_on_update_option'] = 'onesmtp_oauth_disconnect_blocked_7';

        $repository = new ProviderRepository();
        $result = $this->coordinator($repository)->disconnect(7);

        self::assertFalse($result['ok']);
        self::assertSame('disconnect_blocked', $result['code']);
        self::assertSame([], (new ProviderRepository())->getActiveProviders());
    }

    public function test_atomic_cleanup_succeeds_even_when_stale_block_marker_write_path_fails(): void
    {
        $this->provider(7, ProviderTypes::GMAIL, [
            'client_id' => 'google-client',
            'client_secret' => 'google-secret',
            'refresh_token' => 'refresh-fixture',
            'access_token' => 'access-fixture',
        ]);
        $GLOBALS['wpdb']->activeProviders = [ $GLOBALS['wpdb']->providerRowsById[7] ];
        $GLOBALS['onesmtp_test_throw_on_update_option'] = 'onesmtp_oauth_disconnect_blocked_7';

        $result = $this->coordinator()->disconnect(7);

        self::assertTrue($result['ok']);
        self::assertSame('disconnected', $result['code']);
        self::assertSame(0, $GLOBALS['wpdb']->providerRowsById[7]['is_active']);
        self::assertArrayNotHasKey('access_token', json_decode( (string) $GLOBALS['wpdb']->providerRowsById[7]['config_json'], true));
    }

    public function test_disconnect_catches_secret_vault_failure_and_blocks_next_request(): void
    {
        $this->provider(7, ProviderTypes::GMAIL, [
            'client_id' => 'google-client',
            'client_secret' => 'google-secret',
            'refresh_token' => 'refresh-fixture',
            'access_token' => 'access-fixture',
        ]);
        $GLOBALS['wpdb']->activeProviders = [ $GLOBALS['wpdb']->providerRowsById[7] ];
        $vault = new \OneSMTP\Security\SecretVault(static fn (): string => throw new \RuntimeException('synthetic vault failure'));
        $repository = new ProviderRepository($vault);

        $result = $this->coordinator($repository)->disconnect(7);

        self::assertTrue($result['ok']);
        self::assertSame('disconnected_local_blocked', $result['code']);
        self::assertSame([], (new ProviderRepository())->getActiveProviders());
    }

    public function test_gmail_api_send_uses_bearer_header_and_normalizes_message_id(): void
    {
        $GLOBALS['onesmtp_test_remote_response'] = [
            'response' => [ 'code' => 200 ],
            'body' => '{"id":"gmail-message-fixture"}',
        ];
        $result = (new GmailAdapter($this->enabledTokenService()))->send(
            [
                'to' => [ 'recipient@example.test' ],
                'subject' => 'Synthetic subject',
                'message' => 'Synthetic body',
                'headers' => [ 'From: Sender <sender@example.test>' ],
            ],
            new ProviderConfig([
                'access_token' => 'access-fixture',
            ])
        );

        self::assertTrue($result->isSuccess());
        self::assertSame('gmail-message-fixture', $result->getProviderMessageId());
        self::assertSame('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', $GLOBALS['onesmtp_test_remote_posts'][0]['url']);
        self::assertSame('Bearer access-fixture', $GLOBALS['onesmtp_test_remote_posts'][0]['args']['headers']['Authorization']);
        self::assertStringNotContainsString('access-fixture', (string) $GLOBALS['onesmtp_test_remote_posts'][0]['args']['body']);
    }

    public function test_gmail_api_raw_payload_has_real_crlf_nested_mime_and_safe_attachment(): void
    {
        $GLOBALS['onesmtp_test_remote_response'] = [
            'response' => [ 'code' => 200 ],
            'body' => '{"id":"gmail-mime-fixture"}',
        ];
        $result = (new GmailAdapter($this->enabledTokenService()))->send(
            [
                'to' => [ 'recipient@example.test' ],
                'subject' => 'Synthetic subject',
                'message' => '<p>HTML body</p>',
                'headers' => [
                    'From: Sender <sender@example.test>',
                    'Content-Type: text/html; charset=UTF-8',
                    'Cc: copy@example.test',
                    'Bcc: archive@example.test',
                ],
                'attachments' => [
                    [
                        'name' => '../unsafe name.txt',
                        'content' => 'attachment fixture',
                    ],
                ],
            ],
            new ProviderConfig([ 'access_token' => 'access-fixture' ])
        );

        self::assertTrue($result->isSuccess());
        $payload = json_decode( (string) $GLOBALS['onesmtp_test_remote_posts'][0]['args']['body'], true);
        self::assertIsArray($payload);
        $raw = (string) ($payload['raw'] ?? '');
        $raw .= str_repeat('=', (4 - strlen($raw) % 4) % 4);
        $mime = base64_decode(strtr($raw, '-_', '+/'), true);

        self::assertIsString($mime);
        self::assertStringContainsString("\r\n", $mime);
        self::assertStringNotContainsString('\\r\\n', $mime);
        self::assertStringContainsString('To: recipient@example.test', $mime);
        self::assertStringContainsString('Cc: copy@example.test', $mime);
        self::assertStringContainsString('Bcc: archive@example.test', $mime);
        self::assertStringContainsString('Content-Type: multipart/mixed;', $mime);
        self::assertStringContainsString('Content-Type: multipart/alternative;', $mime);
        self::assertStringContainsString('HTML body', $mime);
        self::assertStringContainsString('Content-Disposition: attachment; filename="unsafe-name.txt"', $mime);
        self::assertStringContainsString(base64_encode('attachment fixture'), $mime);
    }

    public function test_oauth_delivery_is_default_denied_when_lifecycle_gate_is_off(): void
    {
        $result = (new GmailAdapter(new ProviderOAuthTokenService(null, null, null, new FeatureGate())))->send(
            [
                'to' => [ 'recipient@example.test' ],
                'message' => 'Body',
                'headers' => [],
            ],
            new ProviderConfig([
                'client_id' => 'client-fixture',
                'client_secret' => 'secret-fixture',
                'refresh_token' => 'refresh-fixture',
            ])
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('feature_unavailable', $result->getCode());
        self::assertSame([], $GLOBALS['onesmtp_test_remote_posts']);
    }

    public function test_zoho_oauth_delivery_is_default_denied_when_lifecycle_gate_is_off(): void
    {
        $result = (new ZohoMailAdapter(new ZohoOAuthTokenProvider(
            new ProviderOAuthTokenService(null, null, null, new FeatureGate())
        )))->send(
            [
                'to' => [ 'recipient@example.test' ],
                'message' => 'Body',
                'headers' => [],
            ],
            new ProviderConfig([
                'account_id' => 'account-fixture',
                'client_id' => 'client-fixture',
                'client_secret' => 'secret-fixture',
                'refresh_token' => 'refresh-fixture',
            ])
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('feature_unavailable', $result->getCode());
        self::assertSame([], $GLOBALS['onesmtp_test_remote_posts']);
    }

    public function test_legacy_gmail_smtp_configuration_stays_on_smtp_adapter(): void
    {
        $smtp = $this->createMock(SmtpAdapter::class);
        $smtp->expects(self::once())->method('send')->willReturn(
            new \OneSMTP\Providers\SendResult(true, 'accepted', 'SMTP fixture')
        );
        $result = (new GmailAdapter(null, $smtp))->send(
            [
                'to' => [ 'recipient@example.test' ],
                'message' => 'Body',
                'headers' => [],
            ],
            new ProviderConfig([
                'host' => 'smtp.gmail.test',
                'username' => 'user-fixture',
                'password' => 'password-fixture',
            ])
        );

        self::assertTrue($result->isSuccess());
        self::assertSame([], $GLOBALS['onesmtp_test_remote_posts']);
    }

    public function test_gmail_rejects_control_containing_recipient_before_api_call(): void
    {
        $result = (new GmailAdapter($this->enabledTokenService()))->send(
            [
                'to' => [ "victim@example.test\r\nBcc: attacker@example.test" ],
                'message' => 'Body',
                'headers' => [],
            ],
            new ProviderConfig([ 'access_token' => 'access-fixture' ])
        );

        self::assertFalse($result->isSuccess());
        self::assertSame('invalid_recipient', $result->getCode());
        self::assertSame([], $GLOBALS['onesmtp_test_remote_posts']);
    }

    public function test_callback_does_not_report_connected_when_provider_update_fails(): void
    {
        $this->provider(7, ProviderTypes::GMAIL, [
            'client_id' => 'google-client',
            'client_secret' => 'google-secret',
        ]);
        $coordinator = $this->coordinator();
        $start = $coordinator->begin(7);
        parse_str( (string) parse_url( (string) $start['authorization_url'], PHP_URL_QUERY), $query);
        $this->http->responses[] = new ProviderOAuthHttpResponse(200, [
            'access_token' => 'access-fixture',
            'refresh_token' => 'refresh-fixture',
            'expires_in' => 3600,
            'scope' => 'https://www.googleapis.com/auth/gmail.send',
        ]);
        $GLOBALS['wpdb']->failProviderConfigUpdates = true;

        $result = $coordinator->callback(7, [
            'state' => $query['state'],
            'code' => 'code-fixture',
        ]);

        self::assertFalse($result['ok']);
        self::assertSame('local_save_failed', $result['code']);
        self::assertFalse( (bool) get_transient('onesmtp_oauth_verified_7'));
    }

    public function test_wordpress_state_store_uses_atomic_database_claims_for_one_time_consumption(): void
    {
        $clock = static fn (): int => 1700000000;
        $store = new WordPressProviderOAuthStateStore($clock);
        $stateHash = str_repeat('a', 64);
        $record = [
            'provider_id' => 7,
            'provider_type' => ProviderTypes::GMAIL,
        ];
        $claimKey = 'onesmtp_oauth_state_claim_' . $stateHash;
        set_transient('onesmtp_oauth_state_' . $stateHash, $record, 120);
        add_option($claimKey, [ 'expires_at' => 1700000300 ], '', false);

        self::assertNull($store->consume($stateHash));
        self::assertSame($record, get_transient('onesmtp_oauth_state_' . $stateHash));

        delete_option($claimKey);
        self::assertSame($record, $store->consume($stateHash));
        self::assertFalse(get_transient('onesmtp_oauth_state_' . $stateHash));
    }

    public function test_wordpress_state_store_reclaims_an_expired_database_claim(): void
    {
        $store = new WordPressProviderOAuthStateStore(static fn (): int => 1700000000);
        $stateHash = str_repeat('b', 64);
        $record = [ 'provider_id' => 8 ];
        set_transient('onesmtp_oauth_state_' . $stateHash, $record, 120);
        add_option(
            'onesmtp_oauth_state_claim_' . $stateHash,
            [ 'expires_at' => 1699999999 ],
            '',
            false
        );

        self::assertSame($record, $store->consume($stateHash));
        self::assertArrayNotHasKey('onesmtp_oauth_state_claim_' . $stateHash, $GLOBALS['onesmtp_test_options']);
    }

    private function coordinator(?ProviderRepository $repository = null): ProviderOAuthLifecycleCoordinator
    {
        return new ProviderOAuthLifecycleCoordinator(
            $repository ?? new ProviderRepository(),
            new FeatureGate([ FeatureGate::PROVIDER_AUTH_LIFECYCLE => true ], true),
            $this->http,
            $this->states,
            static fn (): int => 1700000000
        );
    }

    private function enabledTokenService(): ProviderOAuthTokenService
    {
        return new ProviderOAuthTokenService(
            null,
            null,
            null,
            new FeatureGate([ FeatureGate::PROVIDER_AUTH_LIFECYCLE => true ], true)
        );
    }

    /** @param array<string,string> $config */
    private function provider(int $id, string $type, array $config): void
    {
        $GLOBALS['wpdb']->providerRowsById[ $id ] = [
            'id' => $id,
            'slug' => 'provider-' . $id,
            'name' => ucfirst($type),
            'adapter_type' => $type,
            'priority' => 1,
            'weight' => 1,
            'is_active' => 0,
            'circuit_state' => 'closed',
            'config_json' => wp_json_encode($config),
        ];
    }
}

final class FakeOAuthHttp implements ProviderOAuthHttpClientInterface
{
    /** @var array<int,ProviderOAuthHttpResponse> */
    public array $responses = [];

    /** @var array<int,array{url:string,headers:array<string,string>,body:array<string,string>}> */
    public array $calls = [];

    public bool $throwOnPost = false;

    public function post(string $url, array $headers, array $body, int $timeout = 15): ProviderOAuthHttpResponse
    {
        if ($this->throwOnPost) {
            throw new \RuntimeException('Synthetic remote revoke failure.');
        }

        $this->calls[] = [
			'url' => $url,
			'headers' => $headers,
			'body' => $body,
		];

        return array_shift($this->responses) ?? new ProviderOAuthHttpResponse(200, []);
    }
}

final class FakeOAuthStates implements ProviderOAuthStateStoreInterface
{
    /** @var array<string,array<string,mixed>> */
    public array $records = [];

    public function put(string $stateHash, array $record, int $ttl): bool
    {
        $this->records[ $stateHash ] = $record + [ 'ttl' => $ttl ];

        return true;
    }

    public function consume(string $stateHash): ?array
    {
        $record = $this->records[ $stateHash ] ?? null;
        unset($this->records[ $stateHash ]);

        return is_array($record) ? $record : null;
    }
}
