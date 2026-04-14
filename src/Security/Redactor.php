<?php

declare(strict_types=1);

namespace OneSMTP\Security;

final class Redactor
{
    private const MASK = '[REDACTED]';
    private const SECRET_PATTERNS = [
        '/(api(?:_|-)?key\s*[=:]\s*)([^\s,;]+)/i',
        '/(token\s*[=:]\s*)([^\s,;]+)/i',
        '/(authorization\s*:\s*bearer\s+)([^\s,;]+)/i',
        '/(password\s*[=:]\s*)([^\s,;]+)/i',
        '/(secret\s*[=:]\s*)([^\s,;]+)/i',
    ];

    public function redactArray(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->redactArray($value);
                continue;
            }

            if (is_string($value)) {
                $result[$key] = $this->isSensitiveKey((string) $key)
                    ? self::MASK
                    : $this->redactText($value);
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }

    public function redactText(string $text, int $maxLength = 300): string
    {
        $redacted = $text;

        foreach (self::SECRET_PATTERNS as $pattern) {
            $redacted = (string) preg_replace($pattern, '$1' . self::MASK, $redacted);
        }

        if (strlen($redacted) > $maxLength) {
            $redacted = substr($redacted, 0, $maxLength) . '...';
        }

        return $redacted;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower(trim($key));

        return (bool) preg_match('/pass|secret|token|api(?:_|-)?key/', $key);
    }
}
