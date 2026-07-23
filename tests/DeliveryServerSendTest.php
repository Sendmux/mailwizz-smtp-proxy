<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/framework-stubs.php';
require_once __DIR__ . '/guzzle-stubs.php';
require_once dirname(__DIR__) . '/common/models/DeliveryServerSendmuxWebApi.php';

final class DeliveryServerSendTest extends TestCase
{
    protected function setUp(): void
    {
        GuzzleHttp\Client::$requests = [];
    }

    public function testCampaignSendAddsStableIdempotencyKey(): void
    {
        $server = new DeliveryServerSendmuxWebApi();
        $server->server_id = 42;
        $server->password = 'smx_mbx_test-key';
        $server->from_email = 'sender@example.com';
        $server->from_name = 'Sender';
        $server->timeout = 30;

        $result = $server->send([
            'from' => 'sender@example.com',
            'to' => 'prospect@example.net',
            'subject' => 'A useful introduction',
            'body' => '<p>Hello</p>',
            'campaignUid' => 'CAMPAIGN1',
            'subscriberUid' => 'SUBSCRIBER1',
        ]);

        self::assertSame(['message_id' => 'eml_test'], $result);
        self::assertCount(1, GuzzleHttp\Client::$requests);
        self::assertSame(
            'mailwizz-80f42d364225e43ff9fafba401e243618a21558d45254a0b1436b2e0b29b44a2',
            GuzzleHttp\Client::$requests[0]['options']['headers']['Idempotency-Key'] ?? null
        );
    }

    public function testTransactionalSendWithoutStableMailWizzIdsOmitsIdempotencyKey(): void
    {
        $server = new DeliveryServerSendmuxWebApi();
        $server->server_id = 42;
        $server->password = 'smx_mbx_test-key';
        $server->from_email = 'sender@example.com';
        $server->from_name = 'Sender';

        $result = $server->send([
            'from' => 'sender@example.com',
            'to' => 'recipient@example.net',
            'subject' => 'Your requested update',
            'body' => '<p>Hello</p>',
        ]);

        self::assertSame(['message_id' => 'eml_test'], $result);
        self::assertArrayNotHasKey('Idempotency-Key', GuzzleHttp\Client::$requests[0]['options']['headers']);
    }

    public function testCampaignRetryKeepsIdempotencyKeyWhenMailWizzChangesDeliveryServer(): void
    {
        foreach ([42, 84] as $serverId) {
            $server = new DeliveryServerSendmuxWebApi();
            $server->server_id = $serverId;
            $server->password = 'smx_mbx_test-key';
            $server->from_email = 'sender@example.com';
            $server->from_name = 'Sender';

            $server->send([
                'from' => 'sender@example.com',
                'to' => 'prospect@example.net',
                'subject' => 'A useful introduction',
                'body' => '<p>Hello</p>',
                'campaignUid' => 'CAMPAIGN1',
                'subscriberUid' => 'SUBSCRIBER1',
            ]);
        }

        self::assertCount(2, GuzzleHttp\Client::$requests);
        self::assertSame(
            GuzzleHttp\Client::$requests[0]['options']['headers']['Idempotency-Key'] ?? null,
            GuzzleHttp\Client::$requests[1]['options']['headers']['Idempotency-Key'] ?? null
        );
    }
}
