<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\FailureClassifier;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;

/**
 * Shared transport helpers for provider JSON APIs.
 *
 * Provider-specific adapters still own their endpoint, authentication, and
 * payload shape. This class only centralizes safe HTTP/error handling and the
 * common WordPress message envelope.
 */
abstract class AbstractHttpApiAdapter extends AbstractAdapter
{
    /**
     * @return array{to:array<int,string>,cc:array<int,string>,bcc:array<int,string>,from:array{email:string,name:string},reply_to:string,subject:string,text:string,html:string,is_html:bool}
     */
    protected function envelope(array $message): array
    {
        $headers = $this->normalizeHeaders($message['headers'] ?? []);
        $body = $this->getBody($message);
        $contentType = '';

        foreach ($headers as $header) {
            if (stripos($header, 'content-type:') === 0) {
                $contentType = strtolower(trim(substr($header, 13)));
                break;
            }
        }

        $isHtml = str_contains($contentType, 'text/html');

        $text = $isHtml ? wp_strip_all_tags($body) : $body;

        return [
            'to' => $this->normalizeRecipients($message['to'] ?? []),
            'cc' => $this->extractCc($headers),
            'bcc' => $this->extractBcc($headers),
            'from' => $this->extractFrom($headers),
            'reply_to' => $this->extractFirstAddress($this->extractReplyTo($headers)),
            'subject' => $this->getSubject($message),
            'text' => $text,
            'html' => $isHtml ? $body : '',
            'is_html' => $isHtml,
        ];
    }

    protected function fromString(array $from): string
    {
        $email = (string) ($from['email'] ?? '');
        $name = trim( (string) ($from['name'] ?? ''));

        return $name !== '' ? sprintf('%s <%s>', $name, $email) : $email;
    }

    /**
     * @param array<string,string> $headers
     * @param callable(array<string,mixed>,array<string,string>):?string|null $messageIdExtractor
     */
    protected function postJson(
        string $url,
        array $headers,
        array $payload,
        string $errorCode,
        ?int $timeout = null,
        ?callable $messageIdExtractor = null
    ): SendResult {
        $response = wp_remote_post(
            $url,
            [
                'headers' => array_merge([
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
				], $headers),
                'timeout' => max(5, $timeout ?? 30),
                'body' => wp_json_encode($payload),
            ]
        );

        if (is_wp_error($response)) {
            return new SendResult(
                false,
                $errorCode . '_network_error',
                sanitize_text_field($response->get_error_message()),
                null,
                FailureClassifier::classify($response->get_error_code(), $response->get_error_message())
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        $decoded = is_array($decoded) ? $decoded : [];

        if ($status >= 200 && $status < 300) {
            $messageId = null;
            if ($messageIdExtractor !== null) {
                $messageId = $messageIdExtractor($decoded, $headers);
            }

            if ($messageId === null && function_exists('wp_remote_retrieve_header')) {
                $headerId = wp_remote_retrieve_header($response, 'x-message-id');
                $messageId = is_string($headerId) && $headerId !== '' ? $headerId : null;
            }

            return new SendResult(true, 'accepted', 'Accepted by ' . $errorCode . ' API.', $messageId);
        }

        $message = $this->safeErrorMessage($decoded, $body);

        return new SendResult(
            false,
            $errorCode . '_api_error',
            $message,
            null,
            FailureClassifier::classify($errorCode . '_api_error', $message, $status)
        );
    }

    protected function testEnvelope(string $providerName): array
    {
        return [
            'to' => [sanitize_email( (string) get_option('admin_email'))],
            'subject' => sprintf('[Aculect Mail] %s Connection Test', $providerName),
            'message' => 'Connection test from Aculect Mail.',
            'headers' => [],
        ];
    }

    /** @param array<string,mixed> $decoded */
    private function safeErrorMessage(array $decoded, string $body): string
    {
        $candidates = [];
        foreach (['message', 'error', 'detail', 'description'] as $key) {
            if (isset($decoded[ $key ]) && is_scalar($decoded[ $key ])) {
                $candidates[] = (string) $decoded[ $key ];
            }
        }

        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            foreach ($decoded['errors'] as $error) {
                if (is_scalar($error)) {
                    $candidates[] = (string) $error;
                } elseif (is_array($error)) {
                    foreach (['message', 'detail', 'description'] as $key) {
                        if (isset($error[ $key ]) && is_scalar($error[ $key ])) {
                            $candidates[] = (string) $error[ $key ];
                        }
                    }
                }
            }
        }

        $message = $candidates[0] ?? $body;
        $message = sanitize_text_field( (string) $message);

        return $message !== '' ? substr($message, 0, 500) : 'Provider rejected the message.';
    }
}
