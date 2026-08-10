<?php

declare(strict_types=1);

namespace OneSMTP\Dispatch;

final class RoutingContextBuilder
{
    /**
     * Build a bounded, transient context. The returned values are consumed by
     * dispatch only; callers must not persist or log this array.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function fromPayload(array $payload): array
    {
        $source = isset($payload[ \OneSMTP\Pipeline\MailSourceAttributor::PAYLOAD_KEY ])
            && is_array($payload[ \OneSMTP\Pipeline\MailSourceAttributor::PAYLOAD_KEY ])
            ? $payload[ \OneSMTP\Pipeline\MailSourceAttributor::PAYLOAD_KEY ]
            : [];
        $sender = $this->addresses($payload['from'] ?? $payload['sender'] ?? '');
        $recipients = array_merge(
            $this->addresses($payload['to'] ?? ''),
            $this->addresses($payload['cc'] ?? ''),
            $this->addresses($payload['bcc'] ?? '')
        );

        $sourceType = $this->bounded( (string) ($source['type'] ?? ''));
        $sourceSlug = $this->bounded( (string) ($source['slug'] ?? ''));

        return [
            'sender' => $sender,
            'recipient' => array_values(array_unique($recipients)),
            'subject' => $this->bounded( (string) ($payload['subject'] ?? '')),
            'content' => $this->bounded( (string) ($payload['message'] ?? '')),
            'source' => $sourceSlug !== '' ? $sourceSlug : $sourceType,
            'source_type' => $sourceType,
            'source_slug' => $sourceSlug,
            'source_name' => $this->bounded( (string) ($source['name'] ?? '')),
            'source_origin' => $this->bounded( (string) ($source['origin'] ?? '')),
        ];
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function addresses(mixed $value): array
    {
        if (is_array($value)) {
            $values = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $item = $item['email'] ?? '';
                }
                if (is_scalar($item)) {
                    $values = array_merge($values, $this->addresses( (string) $item));
                }
            }

            return $values;
        }

        if ( ! is_scalar($value)) {
            return [];
        }

        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+/i', (string) $value, $matches);
        $addresses = [];
        foreach ($matches[0] as $address) {
            $address = strtolower(trim(sanitize_email( (string) $address)));
            if ($address !== '') {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }

    private function bounded(string $value): string
    {
        $value = trim($value);

        return strlen($value) > RoutingRuleNormalizer::MAX_MATCH_LENGTH
            ? substr($value, 0, RoutingRuleNormalizer::MAX_MATCH_LENGTH)
            : $value;
    }
}
