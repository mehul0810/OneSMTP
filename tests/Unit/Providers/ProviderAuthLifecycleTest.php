<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Providers;

use OneSMTP\Providers\Auth\ProviderAuthCapabilities;
use OneSMTP\Providers\Auth\ProviderAuthContext;
use OneSMTP\Providers\Auth\ProviderAuthEvaluator;
use OneSMTP\Providers\Auth\ProviderAuthRefreshResult;
use OneSMTP\Providers\Auth\ProviderAuthRefreshState;
use OneSMTP\Providers\Auth\ProviderAuthState;
use OneSMTP\Providers\Auth\ProviderAuthStatus;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;
use OneSMTP\Tests\Support\FakeProviderAuthLifecycleEvaluator;
use PHPUnit\Framework\TestCase;

final class ProviderAuthLifecycleTest extends TestCase
{
    public function test_states_are_bounded_and_capabilities_are_side_effect_free(): void
    {
        $states = ProviderAuthState::cases();

        self::assertCount(8, $states);
        self::assertSame(
            [
                'unsupported',
                'static',
                'disconnected',
                'connected',
                'refresh_failed',
                'reauth_required',
                'revoked',
                'unknown',
            ],
            array_map(static fn (ProviderAuthState $state): string => $state->value, $states)
        );

        $statusArrays = array_map(
            static fn (ProviderAuthState $state): array => ProviderAuthStatus::forState($state)->toArray(),
            $states
        );
        foreach ($statusArrays as $status) {
            self::assertSame([ 'state', 'can_reconnect', 'can_revoke' ], array_keys($status));
            self::assertArrayNotHasKey('token', $status);
            self::assertArrayNotHasKey('client_secret', $status);
        }

        self::assertFalse(ProviderAuthStatus::forState(ProviderAuthState::UNSUPPORTED)->canReconnect());
        self::assertFalse(ProviderAuthStatus::forState(ProviderAuthState::STATIC)->canRevoke());
        self::assertTrue(ProviderAuthStatus::forState(ProviderAuthState::CONNECTED)->canReconnect());
        self::assertTrue(ProviderAuthStatus::forState(ProviderAuthState::CONNECTED)->canRevoke());
        self::assertTrue(ProviderAuthStatus::forState(ProviderAuthState::REVOKED)->canReconnect());
        self::assertFalse(ProviderAuthStatus::forState(ProviderAuthState::REVOKED)->canRevoke());
    }

    public function test_injectable_fake_returns_only_the_seeded_redacted_status(): void
    {
        $fake = new FakeProviderAuthLifecycleEvaluator(ProviderAuthStatus::forState(ProviderAuthState::STATIC));
        $context = ProviderAuthContext::fromProviderConfig('fixture-provider', new ProviderConfig([]));

        self::assertSame(ProviderAuthState::STATIC, $fake->evaluate($context)->getState());
        self::assertSame(1, $fake->evaluationCount);
    }

    public function test_zoho_complete_configuration_is_connected_without_refresh_side_effects(): void
    {
        $status = $this->evaluateZoho([
            'client_id' => 'client-id-fixture',
            'client_secret' => 'client-secret-fixture',
            'refresh_token' => 'refresh-token-fixture',
        ]);

        self::assertSame(ProviderAuthState::CONNECTED, $status->getState());
        self::assertTrue($status->canReconnect());
        self::assertTrue($status->canRevoke());
    }

    public function test_zoho_refresh_success_is_connected(): void
    {
        $status = $this->evaluateZoho($this->zohoConfig(), ProviderAuthRefreshResult::success());

        self::assertSame(ProviderAuthState::CONNECTED, $status->getState());
    }

    public function test_zoho_network_failure_is_refresh_failed_and_redacted(): void
    {
        $status = $this->evaluateZoho($this->zohoConfig(), ProviderAuthRefreshResult::networkError());

        self::assertSame(ProviderAuthState::REFRESH_FAILED, $status->getState());
        self::assertSame(
            [
                'state' => 'refresh_failed',
                'can_reconnect' => true,
                'can_revoke' => true,
            ],
            $status->toArray()
        );
    }

    public function test_zoho_invalid_grant_maps_to_reauthentication_without_raw_error_text(): void
    {
        $result = new SendResult(false, 'zoho_oauth_error', 'invalid_grant client-secret-fixture refresh-token-fixture');
        $refresh = ProviderAuthRefreshResult::fromSendResult($result);
        $status = $this->evaluateZoho($this->zohoConfig(), $refresh);

        self::assertSame(ProviderAuthRefreshState::INVALID_CREDENTIALS, $refresh->getState());
        self::assertSame(ProviderAuthState::REAUTH_REQUIRED, $status->getState());
        self::assertNotContains('invalid_grant', $status->toArray());
        self::assertNotContains('client-secret-fixture', $status->toArray());
        self::assertNotContains('refresh-token-fixture', $status->toArray());
    }

    public function test_zoho_revoked_result_maps_to_revoked_with_reconnect_only(): void
    {
        $status = $this->evaluateZoho($this->zohoConfig(), ProviderAuthRefreshResult::revoked());

        self::assertSame(ProviderAuthState::REVOKED, $status->getState());
        self::assertTrue($status->canReconnect());
        self::assertFalse($status->canRevoke());
    }

    public function test_zoho_missing_or_partial_configuration_fails_closed(): void
    {
        $missing = $this->evaluateZoho([]);
        $partial = $this->evaluateZoho([ 'client_id' => 'client-id-fixture' ]);

        self::assertSame(ProviderAuthState::DISCONNECTED, $missing->getState());
        self::assertSame(ProviderAuthState::REAUTH_REQUIRED, $partial->getState());
    }

    public function test_gmail_oauth_shaped_configuration_is_not_reported_as_connected(): void
    {
        $context = ProviderAuthContext::fromProviderConfig(
            'gmail',
            new ProviderConfig([
                'client_id' => 'client-id-fixture',
                'client_secret' => 'client-secret-fixture',
                'refresh_token' => 'refresh-token-fixture',
            ])
        );
        $status = (new ProviderAuthEvaluator())->evaluate($context);

        self::assertSame(ProviderAuthState::UNSUPPORTED, $status->getState());
        self::assertFalse($status->canReconnect());
        self::assertFalse($status->canRevoke());

        $refreshedStatus = (new ProviderAuthEvaluator())->evaluate(
            ProviderAuthContext::fromProviderConfig('gmail', new ProviderConfig([
                'client_id' => 'client-id-fixture',
                'client_secret' => 'client-secret-fixture',
                'refresh_token' => 'refresh-token-fixture',
            ]), ProviderAuthRefreshResult::success())
        );

        self::assertSame(ProviderAuthState::UNSUPPORTED, $refreshedStatus->getState());
    }

    public function test_static_unknown_and_unrecognized_refresh_outcomes_fail_closed(): void
    {
        $evaluator = new ProviderAuthEvaluator();
        $static = $evaluator->evaluate(ProviderAuthContext::fromProviderConfig('smtp', new ProviderConfig([])));
        $unknown = $evaluator->evaluate(ProviderAuthContext::fromProviderConfig('future_provider', new ProviderConfig([])));
        $zohoUnknown = $this->evaluateZoho($this->zohoConfig(), ProviderAuthRefreshResult::unknown());

        self::assertSame(ProviderAuthState::STATIC, $static->getState());
        self::assertSame(ProviderAuthState::UNKNOWN, $unknown->getState());
        self::assertSame(ProviderAuthState::UNKNOWN, $zohoUnknown->getState());
        self::assertFalse($unknown->canReconnect());
        self::assertFalse($unknown->canRevoke());
    }

    public function test_existing_send_results_map_to_bounded_refresh_outcomes_without_exposing_values(): void
    {
        $network = ProviderAuthRefreshResult::fromSendResult(new SendResult(false, 'zoho_oauth_network_error', 'private network diagnostic'));
        $invalid = ProviderAuthRefreshResult::fromSendResult(new SendResult(false, 'zoho_oauth_error', 'invalid_grant private token value'));
        $unknown = ProviderAuthRefreshResult::fromSendResult(new SendResult(false, 'provider_failure', 'private account@example.test'));

        self::assertSame(ProviderAuthRefreshState::NETWORK_ERROR, $network->getState());
        self::assertSame(ProviderAuthRefreshState::INVALID_CREDENTIALS, $invalid->getState());
        self::assertSame(ProviderAuthRefreshState::UNKNOWN, $unknown->getState());
    }

    /**
     * @param array<string,string> $config
     */
    private function evaluateZoho(array $config, ?ProviderAuthRefreshResult $refreshResult = null): ProviderAuthStatus
    {
        return (new ProviderAuthEvaluator())->evaluate(
            ProviderAuthContext::fromProviderConfig('zoho_mail', new ProviderConfig($config), $refreshResult)
        );
    }

    /**
     * @return array<string,string>
     */
    private function zohoConfig(): array
    {
        return [
            'client_id' => 'client-id-fixture',
            'client_secret' => 'client-secret-fixture',
            'refresh_token' => 'refresh-token-fixture',
        ];
    }
}
