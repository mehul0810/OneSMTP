<?php

declare(strict_types=1);

namespace OneSMTP\Analytics;

use OneSMTP\Security\Redactor;

/**
 * Produces stable, privacy-safe labels for the existing stored message subject.
 *
 * Advanced reports never read payload_json. The subject is normalized for a
 * deterministic group key, then redacted and bounded before it can reach an
 * admin response. Callers still escape the returned label for their output
 * context.
 */
final class SubjectGroupFormatter
{
    private const LABEL_LIMIT = 80;

    public function __construct(private ?Redactor $redactor = null)
    {
        $this->redactor = $redactor ?? new Redactor();
    }

    public function key(?string $subject): string
    {
        return hash('sha256', strtolower($this->normalize($subject)));
    }

    public function label(?string $subject): string
    {
        $normalized = $this->normalize($subject);
        if ($normalized === '') {
            return __('No subject', 'onesmtp');
        }

        return $this->redactor->redactText($normalized, self::LABEL_LIMIT);
    }

    private function normalize(?string $subject): string
    {
        $subject = trim( (string) $subject );
        if ($subject === '') {
            return '';
        }

        $subject = function_exists('wp_strip_all_tags')
            ? \wp_strip_all_tags($subject)
            : (preg_replace('/<[^>]*>/', '', $subject) ?? $subject);
        $subject = preg_replace('/\s+/u', ' ', $subject) ?? $subject;

        return trim($subject);
    }
}
