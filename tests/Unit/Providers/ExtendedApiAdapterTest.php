<?php

declare(strict_types=1);

namespace OneSMTP\Tests\Unit\Providers;

use OneSMTP\Providers\Adapters\ElasticEmailAdapter;
use OneSMTP\Providers\Adapters\MailchimpTransactionalAdapter;
use OneSMTP\Providers\Adapters\MailgunAdapter;
use OneSMTP\Providers\Adapters\MailerSendAdapter;
use OneSMTP\Providers\Adapters\MailjetAdapter;
use OneSMTP\Providers\Adapters\ResendAdapter;
use OneSMTP\Providers\Adapters\Smtp2GoAdapter;
use OneSMTP\Providers\Adapters\SparkPostAdapter;
use OneSMTP\Providers\Adapters\ZeptoMailAdapter;
use OneSMTP\Providers\Adapters\ZohoMailAdapter;
use OneSMTP\Providers\Adapters\EmailitAdapter;
use OneSMTP\Providers\Adapters\NetcoreAdapter;
use OneSMTP\Providers\ProviderAdapterRegistry;
use OneSMTP\Providers\ProviderConfig;
use PHPUnit\Framework\TestCase;

final class ExtendedApiAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['onesmtp_test_remote_posts'] = [];
        $GLOBALS['onesmtp_test_remote_response_queue'] = [];
        $GLOBALS['onesmtp_test_transients'] = [];
        $GLOBALS['onesmtp_test_remote_response'] = [
            'response' => ['code' => 202],
            'body' => '{"id":"provider-message-id","MessageID":"provider-message-id","request_id":"request-id","data":{"email_id":"email-id"},"results":{"id":"transmission-id"}}',
        ];
    }

    public function test_registry_exposes_the_major_transactional_provider_adapters(): void
    {
        $registry = new ProviderAdapterRegistry();

        self::assertInstanceOf(MailgunAdapter::class, $registry->get('mailgun'));
        self::assertInstanceOf(ResendAdapter::class, $registry->get('resend'));
        self::assertInstanceOf(MailjetAdapter::class, $registry->get('mailjet'));
        self::assertInstanceOf(SparkPostAdapter::class, $registry->get('sparkpost'));
        self::assertInstanceOf(MailerSendAdapter::class, $registry->get('mailersend'));
        self::assertInstanceOf(Smtp2GoAdapter::class, $registry->get('smtp2go'));
        self::assertInstanceOf(ElasticEmailAdapter::class, $registry->get('elastic_email'));
        self::assertInstanceOf(ZeptoMailAdapter::class, $registry->get('zeptomail'));
        self::assertInstanceOf(MailchimpTransactionalAdapter::class, $registry->get('mailchimp_transactional'));
        self::assertInstanceOf(ZohoMailAdapter::class, $registry->get('zoho_mail'));
        self::assertInstanceOf(EmailitAdapter::class, $registry->get('emailit'));
        self::assertInstanceOf(NetcoreAdapter::class, $registry->get('netcore'));
    }

    public function test_adapters_send_provider_specific_payloads(): void
    {
        $cases = [
            [new ResendAdapter(), ['api_key' => 'resend-key'], 'https://api.resend.com/emails'],
            [
                new MailjetAdapter(),
                [
                    'api_key' => 'mailjet-key',
                    'secret_key' => 'mailjet-secret',
                ],
                'https://api.mailjet.com/v3.1/send',
            ],
            [
                new SparkPostAdapter(),
                [
                    'api_key' => 'sparkpost-key',
                    'region' => 'eu',
                ],
                'https://api.eu.sparkpost.com/api/v1/transmissions',
            ],
            [new MailerSendAdapter(), ['api_key' => 'mailersend-key'], 'https://api.mailersend.com/v1/email'],
            [new Smtp2GoAdapter(), ['api_key' => 'smtp2go-key'], 'https://api.smtp2go.com/v3/email/send'],
            [new ElasticEmailAdapter(), ['api_key' => 'elastic-key'], 'https://api.elasticemail.com/v4/emails/transactional'],
            [new ZeptoMailAdapter(), ['api_key' => 'zepto-token'], 'https://api.zeptomail.com/v1.1/email'],
            [new MailchimpTransactionalAdapter(), ['api_key' => 'mailchimp-key'], 'https://mandrillapp.com/api/1.0/messages/send.json'],
            [
                new ZohoMailAdapter(),
                [
                    'account_id' => '12345',
                    'access_token' => 'zoho-token',
                    'region' => 'com',
                ],
                'https://mail.zoho.com/api/accounts/12345/messages',
            ],
            [new EmailitAdapter(), ['api_key' => 'emailit-key'], 'https://api.emailit.com/v2/emails'],
            [
                new NetcoreAdapter(),
                [
                    'api_key' => 'netcore-key',
                    'region' => 'us',
                ],
                'https://emailapi.netcorecloud.net/v5/mail/send',
            ],
        ];

        foreach ($cases as [$adapter, $config, $url]) {
            $result = $adapter->send($this->message(), new ProviderConfig($config));

            self::assertTrue($result->isSuccess(), $adapter->getSlug());
            $request = end($GLOBALS['onesmtp_test_remote_posts']);
            self::assertSame($url, $request['url'], $adapter->getSlug());
            self::assertSame('application/json', $request['args']['headers']['Content-Type'], $adapter->getSlug());
        }
    }

    public function test_mailgun_uses_regional_multipart_endpoint_and_authentication(): void
    {
        $result = (new MailgunAdapter())->send(
            $this->message(),
            new ProviderConfig([
				'api_key' => 'mailgun-key',
				'domain' => 'mg.example.test',
				'region' => 'eu',
			])
        );

        self::assertTrue($result->isSuccess());
        $request = end($GLOBALS['onesmtp_test_remote_posts']);
        self::assertSame('https://api.eu.mailgun.net/v3/mg.example.test/messages', $request['url']);
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Expected header verifies RFC 7617 Basic authentication encoding.
        self::assertSame('Basic ' . base64_encode('api:mailgun-key'), $request['args']['headers']['Authorization']);
        self::assertStringContainsString('multipart/form-data; boundary=', $request['args']['headers']['Content-Type']);
        self::assertStringContainsString('name="to"', $request['args']['body']);
    }

    public function test_smtp2go_uses_the_selected_api_region(): void
    {
        $result = (new Smtp2GoAdapter())->send(
            $this->message(),
            new ProviderConfig([
				'api_key' => 'smtp2go-key',
				'region' => 'eu',
			])
        );

        self::assertTrue($result->isSuccess());
        $request = end($GLOBALS['onesmtp_test_remote_posts']);
        self::assertSame('https://eu-api.smtp2go.com/v3/email/send', $request['url']);
    }

    public function test_emailit_uses_message_uuid_as_idempotency_key(): void
    {
        $message = $this->message();
        $message['headers'][] = 'X-OneSMTP-Message-ID: 58bc610a-73dd-4b57-b19d-94f900748cff';

        self::assertTrue((new EmailitAdapter())->send($message, new ProviderConfig(['api_key' => 'emailit-key']))->isSuccess());

        $request = end($GLOBALS['onesmtp_test_remote_posts']);
        self::assertSame('58bc610a-73dd-4b57-b19d-94f900748cff', $request['args']['headers']['Idempotency-Key']);
    }

    public function test_zoho_refreshes_and_encrypts_short_lived_access_token(): void
    {
        $GLOBALS['onesmtp_test_remote_response_queue'] = [
            [
				'response' => ['code' => 200],
				'body' => '{"access_token":"fresh-token","expires_in":3600}',
			],
            [
				'response' => ['code' => 202],
				'body' => '{"data":{"messageId":"zoho-message"}}',
			],
        ];

        $result = (new ZohoMailAdapter())->send($this->message(), new ProviderConfig([
            'account_id' => '12345',
            'client_id' => 'client-id',
            'client_secret' => 'client-secret',
            'refresh_token' => 'refresh-token',
            'region' => 'eu',
        ]));

        self::assertTrue($result->isSuccess());
        self::assertCount(2, $GLOBALS['onesmtp_test_remote_posts']);
        self::assertSame('https://accounts.zoho.eu/oauth/v2/token', $GLOBALS['onesmtp_test_remote_posts'][0]['url']);
        self::assertSame('https://mail.zoho.eu/api/accounts/12345/messages', $GLOBALS['onesmtp_test_remote_posts'][1]['url']);
        self::assertSame('Zoho-oauthtoken fresh-token', $GLOBALS['onesmtp_test_remote_posts'][1]['args']['headers']['Authorization']);
        $cached = reset($GLOBALS['onesmtp_test_transients']);
        self::assertStringStartsWith('onesmtp:v1:gcm:', (string) $cached);
    }

    public function test_new_adapters_fail_closed_when_credentials_are_missing(): void
    {
        foreach ([
            new MailgunAdapter(),
            new ResendAdapter(),
            new MailjetAdapter(),
            new SparkPostAdapter(),
            new MailerSendAdapter(),
            new Smtp2GoAdapter(),
            new ElasticEmailAdapter(),
            new ZeptoMailAdapter(),
            new MailchimpTransactionalAdapter(),
            new ZohoMailAdapter(),
            new EmailitAdapter(),
            new NetcoreAdapter(),
        ] as $adapter) {
            self::assertFalse($adapter->send($this->message(), new ProviderConfig([]))->isSuccess(), $adapter->getSlug());
        }

        self::assertCount(0, $GLOBALS['onesmtp_test_remote_posts']);
    }

    private function message(): array
    {
        return [
            'to' => ['customer@example.test'],
            'subject' => 'Subject',
            'message' => 'Body',
            'headers' => [
                'From: Example Sender <sender@example.test>',
                'Reply-To: reply@example.test',
                'Cc: cc@example.test',
                'Bcc: bcc@example.test',
            ],
        ];
    }
}
