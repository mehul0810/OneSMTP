<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Providers;

use OneSMTP\Product\FeatureGate;
use OneSMTP\Providers\Auth\ProviderOAuthHttpClientInterface;
use OneSMTP\Providers\Auth\ProviderOAuthHttpResponse;
use OneSMTP\Providers\Auth\ProviderOAuthLifecycleCoordinator;
use OneSMTP\Providers\Auth\ProviderOAuthStateStoreInterface;
use OneSMTP\Providers\Adapters\GmailAdapter;
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

    public function test_gmail_api_send_uses_bearer_header_and_normalizes_message_id(): void
    {
        $GLOBALS['onesmtp_test_remote_response'] = [
            'response' => [ 'code' => 200 ],
            'body' => '{"id":"gmail-message-fixture"}',
        ];
        $result = (new GmailAdapter())->send(
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

    private function coordinator(): ProviderOAuthLifecycleCoordinator
    {
        return new ProviderOAuthLifecycleCoordinator(
            new ProviderRepository(),
            new FeatureGate([ FeatureGate::PROVIDER_AUTH_LIFECYCLE => true ], true),
            $this->http,
            $this->states,
            static fn (): int => 1700000000
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

    public function post(string $url, array $headers, array $body, int $timeout = 15): ProviderOAuthHttpResponse
    {
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
