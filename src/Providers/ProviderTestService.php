<?php

declare(strict_types=1);

namespace OneSMTP\Providers;

use OneSMTP\Pipeline\SenderIdentityApplier;
use OneSMTP\Repository\AttemptRepository;
use OneSMTP\Repository\EventRepository;
use OneSMTP\Repository\MessageRepository;
use OneSMTP\Settings\SenderIdentityRepository;
use OneSMTP\Settings\SimulationModeSettingsRepository;

final class ProviderTestService
{
    private ProviderDeliveryManager $deliveryManager;
    private SenderIdentityApplier $senderIdentity;

    /** @var callable():int */
    private $monotonicNow;

    public function __construct(
        private MessageRepository $messages,
        private AttemptRepository $attempts,
        private EventRepository $events,
        ?ProviderDeliveryManager $deliveryManager = null,
        ?SenderIdentityRepository $senderIdentity = null,
        private ?SimulationModeSettingsRepository $simulationMode = null,
        ?callable $monotonicNow = null
    ) {
        $this->deliveryManager = $deliveryManager ?? new ProviderDeliveryManager();
        $this->senderIdentity = new SenderIdentityApplier($senderIdentity ?? new SenderIdentityRepository());
        $this->simulationMode = $simulationMode ?? new SimulationModeSettingsRepository();
        $this->monotonicNow = $monotonicNow ?? static fn (): int => hrtime(true);
    }

    /** @return array{result:SendResult,message_id:int,simulated:bool} */
    public function send(array $provider, array $payload): array
    {
        $messageUuid = (string) wp_generate_uuid4();
        $headers = isset($payload['headers']) && is_array($payload['headers']) ? $payload['headers'] : [];
        $headers[] = 'X-OneSMTP-Message-ID: ' . $messageUuid;
        $payload['headers'] = $headers;
        $payload = $this->applyProviderIdentity($provider, $this->senderIdentity->apply($payload));
        $messageId = $this->messages->create($payload, 1, $messageUuid);
        if ($messageId <= 0) {
            return [
                'result' => new SendResult(false, 'test_log_failed', 'Unable to create the provider test log.'),
                'message_id' => 0,
                'simulated' => false,
            ];
        }

        $providerId = (int) ($provider['id'] ?? 0);
        if ($this->simulationMode->get()->isEnabled()) {
            $this->messages->markSimulated($messageId);
            $this->events->add('message_simulated', [
				'reason' => 'simulation_mode',
				'trigger' => 'provider_test',
			], $messageId, $providerId);

            return [
                'result' => new SendResult(true, 'simulated', 'Test email captured by simulation mode; no provider was contacted.'),
                'message_id' => $messageId,
                'simulated' => true,
            ];
        }

        $startedAt = ($this->monotonicNow)();
        $result = $this->deliveryManager->send($provider, $payload);
        $latencyMs = max(0, (int) round(((($this->monotonicNow)()) - $startedAt) / 1_000_000));
        $this->attempts->add([
            'message_id' => $messageId,
            'attempt_no' => 1,
            'provider_id' => $providerId > 0 ? $providerId : null,
            'trigger_type' => 'provider_test',
            'result' => $result->isSuccess() ? 'sent' : 'fail',
            'error_code' => $result->isSuccess() ? null : $result->getCode(),
            'error_message' => $result->isSuccess() ? null : $result->getMessage(),
            'failure_category' => $result->isSuccess() ? null : $result->getFailureCategory(),
            'latency_ms' => $latencyMs,
            'provider_message_id' => $result->getProviderMessageId(),
        ]);

        if ($result->isSuccess()) {
            $this->messages->markSent($messageId, $providerId);
            $this->events->add('provider_test_sent', ['code' => $result->getCode()], $messageId, $providerId);
        } else {
            $this->messages->markFailedTerminal($messageId, 1);
            $this->events->add(
                'provider_test_failed',
                [
					'code' => $result->getCode(),
					'failure_category' => $result->getFailureCategory(),
				],
                $messageId,
                $providerId
            );
        }

        return [
			'result' => $result,
			'message_id' => $messageId,
			'simulated' => false,
		];
    }

    private function applyProviderIdentity(array $provider, array $payload): array
    {
        $config = isset($provider['config']) && is_array($provider['config']) ? $provider['config'] : [];
        $email = sanitize_email( (string) ($config['from_email'] ?? ''));
        if ($email === '') {
            return $payload;
        }

        $name = sanitize_text_field( (string) ($config['from_name'] ?? ''));
        $headers = isset($payload['headers']) && is_array($payload['headers']) ? $payload['headers'] : [];
        $headers = array_values(array_filter(
            array_map('strval', $headers),
            static fn (string $header): bool => stripos($header, 'from:') !== 0
        ));
        $headers[] = sprintf('From: %s%s', $name !== '' ? $name . ' ' : '', '<' . $email . '>');
        $payload['headers'] = $headers;

        return $payload;
    }
}
