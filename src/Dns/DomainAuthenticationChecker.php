<?php

declare(strict_types=1);

namespace OneSMTP\Dns;

final class DomainAuthenticationChecker
{
    public const STATUS_PRESENT = 'present';
    public const STATUS_MISSING = 'missing';
    public const STATUS_INCONCLUSIVE = 'inconclusive';

    public function __construct(private ?DnsResolverInterface $resolver = null)
    {
        $this->resolver = $resolver ?? new NativeDnsResolver();
    }

    /**
     * @return array{domain:string,spf:array{status:string,query:string},dkim:array{status:string,query:string,selector:string},dmarc:array{status:string,query:string}}
     */
    public function check(string $domain, string $dkimSelector = ''): array
    {
        $domain = $this->normalizeDomain($domain);
        $selector = $this->normalizeSelector($dkimSelector);

        return [
            'domain' => $domain,
            'spf' => [
                'status' => $this->recordStatus($domain, '/^v=spf1(?:\s|$)/i'),
                'query' => $domain,
            ],
            'dkim' => [
                'status' => $selector !== ''
                    ? $this->recordStatus($selector . '._domainkey.' . $domain, '/(^|\s)v=DKIM1(?:\s|;|$)|(^|\s)k=(?:rsa|ed25519)(?:\s|;|$)/i')
                    : self::STATUS_INCONCLUSIVE,
                'query' => $selector !== '' ? $selector . '._domainkey.' . $domain : '',
                'selector' => $selector,
            ],
            'dmarc' => [
                'status' => $this->recordStatus('_dmarc.' . $domain, '/^v=DMARC1(?:\s|;|$)/i'),
                'query' => '_dmarc.' . $domain,
            ],
        ];
    }

    public function lookupAvailable(): bool
    {
        return $this->resolver->isAvailable();
    }

    private function recordStatus(string $query, string $pattern): string
    {
        if (! $this->lookupAvailable() || $query === '') {
            return self::STATUS_INCONCLUSIVE;
        }

        foreach ($this->resolver->txt($query) as $txt) {
            if (preg_match($pattern, trim($txt)) === 1) {
                return self::STATUS_PRESENT;
            }
        }

        return self::STATUS_MISSING;
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('/[^a-z0-9.-]/', '', $domain) ?? '';
        $domain = trim($domain, '.');

        return $domain;
    }

    private function normalizeSelector(string $selector): string
    {
        $selector = strtolower(trim($selector));
        $selector = preg_replace('/[^a-z0-9._-]/', '', $selector) ?? '';

        return trim($selector, '.');
    }
}
