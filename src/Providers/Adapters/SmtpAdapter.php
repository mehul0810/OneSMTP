<?php

declare(strict_types=1);

namespace OneSMTP\Providers\Adapters;

use OneSMTP\Providers\ProviderAdapterInterface;
use OneSMTP\Providers\ProviderConfig;
use OneSMTP\Providers\SendResult;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

class SmtpAdapter extends AbstractAdapter implements ProviderAdapterInterface
{
    public function getSlug(): string
    {
        return 'smtp';
    }

    public function send(array $message, ProviderConfig $config): SendResult
    {
        $this->ensureMailerLoaded();

        $mailer = new PHPMailer(true);

        try {
            $mailer->isSMTP();
            $mailer->Host = (string) $config->get('host', '');
            $mailer->Port = (int) $config->get('port', 587);
            $mailer->SMTPAuth = (bool) $config->get('auth', true);
            $mailer->Username = (string) $config->get('username', '');
            $mailer->Password = (string) $config->get('password', '');
            $mailer->SMTPSecure = (string) $config->get('encryption', PHPMailer::ENCRYPTION_STARTTLS);
            $mailer->Timeout = max(5, (int) $config->get('timeout', 30));

            $from = $this->extractFrom($this->normalizeHeaders($message['headers'] ?? []));
            $mailer->setFrom((string) $from['email'], (string) $from['name']);

            $to = $this->normalizeRecipients($message['to'] ?? []);
            if ($to === []) {
                return new SendResult(false, 'invalid_recipient', 'No valid recipient found.');
            }

            foreach ($to as $recipient) {
                $mailer->addAddress($recipient);
            }

            $mailer->Subject = $this->getSubject($message);
            $mailer->Body = $this->getBody($message);
            $mailer->isHTML((bool) $config->get('is_html', false));

            $mailer->send();

            return new SendResult(true, 'accepted', 'SMTP provider accepted the message.', $mailer->getLastMessageID() ?: null);
        } catch (PHPMailerException $e) {
            return new SendResult(false, 'smtp_error', $e->getMessage());
        }
    }

    public function testConnection(ProviderConfig $config): SendResult
    {
        $probe = [
            'to' => [sanitize_email((string) get_option('admin_email'))],
            'subject' => '[OneSMTP] SMTP Connection Test',
            'message' => 'Connection test from OneSMTP.',
            'headers' => [],
        ];

        return $this->send($probe, $config);
    }

    protected function ensureMailerLoaded(): void
    {
        if (class_exists(PHPMailer::class)) {
            return;
        }

        if (! defined('ABSPATH')) {
            return;
        }

        require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
        require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
        require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
    }
}

