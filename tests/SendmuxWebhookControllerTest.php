<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/framework-stubs.php';
require_once dirname(__DIR__) . '/common/components/SendmuxWebhookSignatureVerifier.php';
require_once dirname(__DIR__) . '/frontend/controllers/SendmuxExtFrontendDswhController.php';

final class SendmuxWebhookControllerTest extends TestCase
{
    protected function setUp(): void
    {
        DeliveryServer::$found = null;
        DeliveryServer::$typedFound = null;
        Campaign::$found = null;
        ListSubscriber::$found = null;
        CampaignBounceLog::$count = 0;
        CampaignBounceLog::$saved = [];
        FakeRequest::$rawBody = '';
        FakeRequest::$server = [];
        Yii::$logs = [];
        Response::$statusCode = 200;
        container()->feedbackLoop->actions = [];
        hooks()->actions = [];
        app()->endRequestHandlers->handlers = [];
    }

    public function testCurrentSignedPermanentBounceCreatesHardBounceAndBlacklistsSubscriber(): void
    {
        $secret = 'whsec_controller-test';
        $body = json_encode([
            'id' => 'evt_current_bounce',
            'type' => 'message.bounced',
            'team_public_id' => 'team_test',
            'occurred_at' => '2026-07-22T10:00:00.000Z',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'recipients' => ['prospect@example.net'],
                'bounce_type' => 'Permanent',
                'bounce_subtype' => 'General',
            ],
        ], JSON_UNESCAPED_SLASHES);

        $server = new DeliveryServer();
        $server->server_id = 7;
        $server->type = 'sendmux-web-api';
        $server->username = 'encrypted-at-rest';
        DeliveryServer::$found = $server;

        $typedServer = clone $server;
        $typedServer->username = $secret;
        DeliveryServer::$typedFound = $typedServer;

        $campaign = new Campaign();
        $campaign->campaign_id = 17;
        $campaign->list_id = 27;
        Campaign::$found = $campaign;

        $subscriber = new ListSubscriber();
        $subscriber->subscriber_id = 37;
        ListSubscriber::$found = $subscriber;

        FakeRequest::$rawBody = $body;
        FakeRequest::$server['HTTP_X_SENDMUX_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', $body, $secret);

        (new SendmuxExtFrontendDswhController())->actionIndex(7);

        self::assertCount(1, CampaignBounceLog::$saved);
        self::assertSame(CampaignBounceLog::BOUNCE_HARD, CampaignBounceLog::$saved[0]->bounce_type);
        self::assertCount(1, $subscriber->blacklistMessages);
        self::assertContains('dswh_before_process', array_column(hooks()->actions, 'name'));
        self::assertCount(1, app()->endRequestHandlers->handlers);
    }

    public function testInvalidSignatureCannotCreateBounceOrBlacklistSubscriber(): void
    {
        $body = json_encode([
            'id' => 'evt_forged_bounce',
            'type' => 'message.bounced',
            'team_public_id' => 'team_test',
            'occurred_at' => '2026-07-22T10:00:00.000Z',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'recipients' => ['prospect@example.net'],
                'bounce_type' => 'Permanent',
                'bounce_subtype' => 'General',
            ],
        ], JSON_UNESCAPED_SLASHES);

        $server = new DeliveryServer();
        $server->server_id = 7;
        $server->type = 'sendmux-web-api';
        $server->username = 'encrypted-at-rest';
        DeliveryServer::$found = $server;

        $typedServer = clone $server;
        $typedServer->username = 'whsec_controller-test';
        DeliveryServer::$typedFound = $typedServer;

        $campaign = new Campaign();
        $campaign->campaign_id = 17;
        $campaign->list_id = 27;
        Campaign::$found = $campaign;

        $subscriber = new ListSubscriber();
        $subscriber->subscriber_id = 37;
        ListSubscriber::$found = $subscriber;

        FakeRequest::$rawBody = $body;
        FakeRequest::$server['HTTP_X_SENDMUX_SIGNATURE'] = 'sha256=' . str_repeat('0', 64);

        (new SendmuxExtFrontendDswhController())->actionIndex(7);

        self::assertSame([], CampaignBounceLog::$saved);
        self::assertSame([], $subscriber->blacklistMessages);
        self::assertSame(401, Response::$statusCode);
    }

    public function testSignedComplaintIsProcessedAfterAnEarlierBounce(): void
    {
        $secret = 'whsec_controller-test';
        $body = json_encode([
            'id' => 'evt_current_complaint',
            'type' => 'message.complained',
            'team_public_id' => 'team_test',
            'occurred_at' => '2026-07-22T10:05:00.000Z',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'recipients' => ['prospect@example.net'],
                'complaint_type' => 'abuse',
                'complaint_subtype' => null,
            ],
        ], JSON_UNESCAPED_SLASHES);

        $server = new DeliveryServer();
        $server->server_id = 7;
        $server->type = 'sendmux-web-api';
        $server->username = 'encrypted-at-rest';
        DeliveryServer::$found = $server;

        $typedServer = clone $server;
        $typedServer->username = $secret;
        DeliveryServer::$typedFound = $typedServer;

        $campaign = new Campaign();
        $campaign->campaign_id = 17;
        $campaign->list_id = 27;
        Campaign::$found = $campaign;

        $subscriber = new ListSubscriber();
        $subscriber->subscriber_id = 37;
        ListSubscriber::$found = $subscriber;

        CampaignBounceLog::$count = 1;
        FakeRequest::$rawBody = $body;
        FakeRequest::$server['HTTP_X_SENDMUX_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', $body, $secret);

        (new SendmuxExtFrontendDswhController())->actionIndex(7);

        self::assertCount(1, container()->feedbackLoop->actions);
        self::assertCount(1, $subscriber->blacklistMessages);
    }

    /**
     * @dataProvider currentNonPermanentBounceProvider
     */
    public function testCurrentSignedNonPermanentBounceMapsToMailWizzType(
        string $sendmuxBounceType,
        string $mailWizzBounceType
    ): void {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);

        $this->sendSignedPayload([
            'id' => 'evt_current_' . strtolower($sendmuxBounceType),
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => $sendmuxBounceType,
                'bounce_subtype' => 'General',
            ],
        ], $secret);

        self::assertCount(1, CampaignBounceLog::$saved);
        self::assertSame($mailWizzBounceType, CampaignBounceLog::$saved[0]->bounce_type);
        self::assertSame([], $subscriber->blacklistMessages);
    }

    public static function currentNonPermanentBounceProvider(): array
    {
        return [
            'transient becomes soft' => ['Transient', CampaignBounceLog::BOUNCE_SOFT],
            'undetermined becomes internal' => ['Undetermined', CampaignBounceLog::BOUNCE_INTERNAL],
        ];
    }

    /**
     * @dataProvider legacyBounceProvider
     */
    public function testLegacySignedBounceUsesFromFallbackAndMapsType(
        string $eventType,
        string $mailWizzBounceType,
        array $details,
        bool $shouldBlacklist
    ): void {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);

        $this->sendSignedPayload([
            'id' => 'evt_legacy_bounce',
            'type' => $eventType,
            'data' => array_merge([
                'from' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
            ], $details),
        ], $secret);

        self::assertCount(1, CampaignBounceLog::$saved);
        self::assertSame($mailWizzBounceType, CampaignBounceLog::$saved[0]->bounce_type);
        self::assertCount($shouldBlacklist ? 1 : 0, $subscriber->blacklistMessages);
    }

    public static function legacyBounceProvider(): array
    {
        return [
            'permanent DSN' => [
                'delivery.dsn-perm-fail',
                CampaignBounceLog::BOUNCE_HARD,
                ['details' => '550 mailbox unavailable'],
                true,
            ],
            'temporary DSN' => [
                'delivery.dsn-temp-fail',
                CampaignBounceLog::BOUNCE_SOFT,
                ['details' => '451 try again later'],
                false,
            ],
            'generic delivery failure' => [
                'delivery.failed',
                CampaignBounceLog::BOUNCE_INTERNAL,
                ['reason' => 'Delivery failed'],
                false,
            ],
        ];
    }

    /**
     * @dataProvider legacyComplaintProvider
     */
    public function testLegacySignedComplaintUsesFromFallback(string $eventType): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);

        $this->sendSignedPayload([
            'id' => 'evt_legacy_complaint',
            'type' => $eventType,
            'data' => [
                'from' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'hostname' => 'feedback.example.net',
            ],
        ], $secret);

        self::assertSame([], CampaignBounceLog::$saved);
        self::assertCount(1, container()->feedbackLoop->actions);
        self::assertCount(1, $subscriber->blacklistMessages);
    }

    public static function legacyComplaintProvider(): array
    {
        return [
            'abuse report' => ['incoming-report.abuse-report'],
            'fraud report' => ['incoming-report.fraud-report'],
        ];
    }

    public function testSignedBatchProcessesBounceAndComplaintEvents(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);

        $this->sendSignedPayload([
            'events' => [
                [
                    'id' => 'evt_batch_bounce',
                    'type' => 'message.bounced',
                    'data' => [
                        'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                        'bounce_type' => 'Transient',
                    ],
                ],
                [
                    'id' => 'evt_batch_complaint',
                    'type' => 'message.complained',
                    'data' => [
                        'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                        'complaint_type' => 'abuse',
                    ],
                ],
            ],
        ], $secret);

        self::assertCount(1, CampaignBounceLog::$saved);
        self::assertSame(CampaignBounceLog::BOUNCE_SOFT, CampaignBounceLog::$saved[0]->bounce_type);
        self::assertCount(1, container()->feedbackLoop->actions);
        self::assertCount(1, $subscriber->blacklistMessages);
    }

    public function testDuplicateSignedBounceDoesNotCreateAnotherBounceOrBlacklistEntry(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        CampaignBounceLog::$count = 1;

        $this->sendSignedPayload([
            'id' => 'evt_duplicate_bounce',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Permanent',
            ],
        ], $secret);

        self::assertSame([], CampaignBounceLog::$saved);
        self::assertSame([], $subscriber->blacklistMessages);
        self::assertStringContainsString('duplicate bounce ignored', implode('\n', array_column(Yii::$logs, 'message')));
    }

    private function arrangeMatchedCampaign(string $secret): ListSubscriber
    {
        $server = new DeliveryServer();
        $server->server_id = 7;
        $server->type = 'sendmux-web-api';
        $server->username = 'encrypted-at-rest';
        DeliveryServer::$found = $server;

        $typedServer = clone $server;
        $typedServer->username = $secret;
        DeliveryServer::$typedFound = $typedServer;

        $campaign = new Campaign();
        $campaign->campaign_id = 17;
        $campaign->list_id = 27;
        Campaign::$found = $campaign;

        $subscriber = new ListSubscriber();
        $subscriber->subscriber_id = 37;
        ListSubscriber::$found = $subscriber;

        return $subscriber;
    }

    private function sendSignedPayload(array $payload, string $secret): void
    {
        $body = (string)json_encode($payload, JSON_UNESCAPED_SLASHES);
        FakeRequest::$rawBody = $body;
        FakeRequest::$server['HTTP_X_SENDMUX_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', $body, $secret);

        (new SendmuxExtFrontendDswhController())->actionIndex(7);
    }
}
