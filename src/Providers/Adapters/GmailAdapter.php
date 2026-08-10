<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\Auth\ProviderOAuthTokenService;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\SendResult;

/** Gmail API adapter for verified customer-owned OAuth credentials. */
final class GmailAdapter extends AbstractHttpApiAdapter implements ProviderAdapterInterface
{
    private const MAX_ATTACHMENT_BYTES = 10485760;
    private const MAX_TOTAL_ATTACHMENT_BYTES = 26214400;

    public function __construct(private ?ProviderOAuthTokenService $tokens = null)
    {
        $this->tokens = $tokens ?? new ProviderOAuthTokenService();
    }

    public function getSlug(): string
    {
        return 'gmail';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $accessToken = $this->tokens->accessToken('gmail', $config);
        if ($accessToken instanceof SendResult) {
            return $accessToken;
        }

        $envelope = $this->envelope($message);
        if ($envelope['to'] === [] && $envelope['cc'] === [] && $envelope['bcc'] === []) {
            return new SendResult(false, 'invalid_recipient', 'No valid recipient found.');
        }

        $mime = $this->buildMime($message, $envelope);
        if ($mime instanceof SendResult) {
            return $mime;
        }

        $raw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');
        $response = wp_remote_post(
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode([ 'raw' => $raw ]),
                'timeout' => 30,
                'redirection' => 0,
            ]
        );
        if (is_wp_error($response)) {
            return new SendResult(false, 'gmail_network_error', 'Gmail is temporarily unavailable.');
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode( (string) wp_remote_retrieve_body($response), true);
        $body = is_array($body) ? $body : [];
        if ($status >= 200 && $status < 300) {
            $messageId = is_scalar($body['id'] ?? null) ? trim( (string) $body['id']) : null;

            return new SendResult(true, 'accepted', 'Accepted by Gmail API.', $messageId !== '' ? $messageId : null);
        }

        $code = $status === 401 ? 'gmail_authentication_error' : 'gmail_api_error';

        return new SendResult(false, $code, $status === 401 ? 'Gmail credentials require reconnection.' : 'Gmail rejected the message.');
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        return $this->send($this->testEnvelope('Gmail'), $config);
    }

    /** @return string|SendResult */
    private function buildMime(array $message, array $envelope): string|SendResult
    {
        $crlf = "\r\n";
        $headers = [
            'From: ' . $this->cleanHeader($this->fromString($envelope['from'])),
            'To: ' . implode(', ', $envelope['to']),
            'Subject: ' . $this->cleanHeader($envelope['subject']),
            'MIME-Version: 1.0',
        ];
        if ($envelope['cc'] !== []) {
            $headers[] = 'Cc: ' . implode(', ', $envelope['cc']);
        }
        if ($envelope['bcc'] !== []) {
            $headers[] = 'Bcc: ' . implode(', ', $envelope['bcc']);
        }
        if ($envelope['reply_to'] !== '') {
            $headers[] = 'Reply-To: ' . $envelope['reply_to'];
        }

        $parts = [];
        if ($envelope['is_html']) {
            $alternativeBoundary = '=_onesmtp_alt_' . wp_generate_uuid4();
            $parts[] = implode(
                $crlf,
                [
                    'Content-Type: multipart/alternative; boundary="' . $alternativeBoundary . '"',
                    '',
                    '--' . $alternativeBoundary,
                    'Content-Type: text/plain; charset=UTF-8',
                    'Content-Transfer-Encoding: 8bit',
                    '',
                    $this->normalizeMimeBody((string) $envelope['text']),
                    '--' . $alternativeBoundary,
                    'Content-Type: text/html; charset=UTF-8',
                    'Content-Transfer-Encoding: 8bit',
                    '',
                    $this->normalizeMimeBody((string) $envelope['html']),
                    '--' . $alternativeBoundary . '--',
                ]
            );
        } else {
            $parts[] = implode(
                $crlf,
                [
                    'Content-Type: text/plain; charset=UTF-8',
                    'Content-Transfer-Encoding: 8bit',
                    '',
                    $this->normalizeMimeBody((string) $envelope['text']),
                ]
            );
        }

        $attachments = $this->attachments($message['attachments'] ?? []);
        if ($attachments instanceof SendResult) {
            return $attachments;
        }

        $boundary = '=_onesmtp_mixed_' . wp_generate_uuid4();
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $body = [];
        foreach ($parts as $part) {
            $body[] = '--' . $boundary . $crlf . $part;
        }
        foreach ($attachments as $attachment) {
            $body[] = '--' . $boundary . $crlf . implode(
                $crlf,
                [
                    'Content-Type: ' . $attachment['type'] . '; name="' . $attachment['name'] . '"',
                    'Content-Disposition: attachment; filename="' . $attachment['name'] . '"',
                    'Content-Transfer-Encoding: base64',
                    '',
                    rtrim(chunk_split(base64_encode($attachment['content']), 76, $crlf)),
                ]
            );
        }
        $body[] = '--' . $boundary . '--';

        return implode($crlf, $headers) . $crlf . $crlf . implode($crlf, $body) . $crlf;
    }

    private function normalizeMimeBody(string $body): string
    {
        return str_replace(
            [ "\r\n", "\r", "\n" ],
            [ "\n", "\n", "\r\n" ],
            $body
        );
    }

    /** @return array<int,array{name:string,type:string,content:string}|SendResult> */
    private function attachments(mixed $attachments): array|SendResult
    {
        if (is_string($attachments)) {
            $attachments = $attachments !== '' ? [ $attachments ] : [];
        }
        if ( ! is_array($attachments)) {
            return [];
        }

        $result = [];
        $total = 0;
        foreach ($attachments as $attachment) {
            $path = is_string($attachment) ? $attachment : (is_array($attachment) ? (string) ($attachment['path'] ?? '') : '');
            $name = is_array($attachment) ? $this->cleanAttachmentName( (string) ($attachment['name'] ?? '')) : '';
            $content = is_array($attachment) && isset($attachment['content']) && is_string($attachment['content']) ? $attachment['content'] : null;
            if ($content === null) {
                if ($path === '' || ! is_readable($path) || ! is_file($path)) {
                    return new SendResult(false, 'attachment_invalid', 'Gmail attachment could not be read.');
                }
                $size = filesize($path);
                if ($size === false || $size > self::MAX_ATTACHMENT_BYTES) {
                    return new SendResult(false, 'attachment_too_large', 'Gmail attachment exceeds the supported size.');
                }
                $content = file_get_contents($path);
                $name = $name !== '' ? $name : $this->cleanAttachmentName(basename($path));
            }
            if ($content === false || strlen($content) > self::MAX_ATTACHMENT_BYTES || $name === '') {
                return new SendResult(false, 'attachment_invalid', 'Gmail attachment could not be read.');
            }
            $total += strlen($content);
            if ($total > self::MAX_TOTAL_ATTACHMENT_BYTES) {
                return new SendResult(false, 'attachment_too_large', 'Gmail attachments exceed the supported size.');
            }
            $result[] = [
                'name' => $this->cleanHeader($name),
                'type' => function_exists('mime_content_type') && $path !== '' ? (string) mime_content_type($path) : 'application/octet-stream',
                'content' => $content,
            ];
        }

        return $result;
    }

    private function cleanHeader(string $value): string
    {
        return trim( (string) preg_replace('/[\r\n]+/', ' ', $value));
    }

    private function cleanAttachmentName(string $value): string
    {
        $value = sanitize_text_field($value);
        $value = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $value);

        return trim($value, '.-');
    }
}
