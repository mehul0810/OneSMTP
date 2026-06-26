<?php

declare(strict_types=1);

namespace OneSMTP\Logging;

use OneSMTP\Settings\AttachmentLoggingSettingsRepository;

final class AttachmentLogSanitizer
{
    public const PAYLOAD_KEY = 'onesmtp_attachment_log';
    private const MAX_ITEMS = 25;
    private const MAX_FILENAME_LENGTH = 120;

    public function __construct(private ?AttachmentLoggingSettingsRepository $settings = null)
    {
        $this->settings = $settings ?? new AttachmentLoggingSettingsRepository();
    }

    public function sanitizePayload(array $payload): array
    {
        $attachments = $this->normalizeAttachments($payload['attachments'] ?? []);
        unset($payload['attachments']);

        if (! $this->settings->get()->isEnabled()) {
            unset($payload[self::PAYLOAD_KEY]);

            return $payload;
        }

        $payload[self::PAYLOAD_KEY] = $this->metadataFor($attachments);

        return $payload;
    }

    /**
     * @return array{enabled:bool,count:int,items:array<int,array{filename:string,extension:string,size_bytes:?int,mime_type:string}>,truncated:bool}
     */
    public function metadataFor(array $attachments): array
    {
        $items = [];

        foreach (array_slice($attachments, 0, self::MAX_ITEMS) as $attachment) {
            $items[] = $this->metadataForAttachment((string) $attachment);
        }

        return [
            'enabled' => true,
            'count' => count($attachments),
            'items' => $items,
            'truncated' => count($attachments) > self::MAX_ITEMS,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function normalizeAttachments(mixed $attachments): array
    {
        if (is_string($attachments)) {
            $attachments = $attachments !== '' ? [$attachments] : [];
        }

        if (! is_array($attachments)) {
            return [];
        }

        $normalized = [];
        foreach ($attachments as $attachment) {
            if (! is_scalar($attachment)) {
                continue;
            }

            $value = trim((string) $attachment);
            if ($value !== '') {
                $normalized[] = $value;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @return array{filename:string,extension:string,size_bytes:?int,mime_type:string}
     */
    private function metadataForAttachment(string $attachment): array
    {
        $filename = $this->safeFilename($attachment);
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $size = is_file($attachment) ? filesize($attachment) : false;

        return [
            'filename' => $filename,
            'extension' => sanitize_key($extension),
            'size_bytes' => is_int($size) ? $size : null,
            'mime_type' => '',
        ];
    }

    private function safeFilename(string $attachment): string
    {
        $normalized = str_replace('\\', '/', $attachment);
        $filename = basename($normalized);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', sanitize_text_field($filename)) ?? '';
        $filename = trim($filename, '.-_');

        if ($filename === '') {
            $filename = 'attachment';
        }

        if (strlen($filename) <= self::MAX_FILENAME_LENGTH) {
            return $filename;
        }

        $extension = (string) pathinfo($filename, PATHINFO_EXTENSION);
        $stem = (string) pathinfo($filename, PATHINFO_FILENAME);
        $suffix = $extension !== '' ? '.' . $extension : '';
        $maxStem = max(10, self::MAX_FILENAME_LENGTH - strlen($suffix) - 3);

        return substr($stem, 0, $maxStem) . '...' . $suffix;
    }
}
