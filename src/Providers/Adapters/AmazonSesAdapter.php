<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;

final class AmazonSesAdapter extends SmtpAdapter
{
    public function getSlug(): string
    {
        return 'amazon_ses';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        return parent::send($message, $this->smtpConfig($config));
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return parent::testConnection($this->smtpConfig($config));
    }

    public static function endpointForRegion(string $region): string
    {
        $region = strtolower(trim($region));
        $region = preg_replace('/[^a-z0-9-]/', '', $region) ?? '';

        return $region !== '' ? 'email-smtp.' . $region . '.amazonaws.com' : '';
    }

    private function smtpConfig(ProviderConfig $config): ProviderConfig
    {
        $values = $config->all();
        $values['host'] = self::endpointForRegion( (string) ($values['region'] ?? 'us-east-1'));
        $values['port'] = isset($values['port']) ? max(1, (int) $values['port']) : 587;
        $values['auth'] = true;
        $values['encryption'] = isset($values['encryption']) && $values['encryption'] !== ''
            ? (string) $values['encryption']
            : 'tls';

        return new ProviderConfig($values);
    }
}
