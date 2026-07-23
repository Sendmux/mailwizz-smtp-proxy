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
        CampaignDeliveryLog::$records = [];
        CampaignDeliveryLogArchive::$records = [];
        EmailBlacklist::$addResult = true;
        EmailBlacklist::$messages = [];
        CustomerEmailBlacklist::$saveResult = true;
        CustomerEmailBlacklist::$records = [];
        ListSubscriber::$found = null;
        ListSubscriber::$persistedStatus = null;
        CampaignBounceLog::$found = null;
        CampaignBounceLog::$saveResult = true;
        CampaignBounceLog::$saved = [];
        CampaignComplainLog::$count = 0;
        CampaignComplainLog::$saveResult = true;
        CampaignComplainLog::$saved = [];
        CampaignTrackUnsubscribe::$count = 0;
        CampaignTrackUnsubscribe::$saveResult = true;
        CampaignTrackUnsubscribe::$saved = [];
        FakeRequest::$rawBody = '';
        FakeRequest::$rawBodyCalls = 0;
        FakeRequest::$server = [];
        Yii::$logs = [];
        Response::$statusCode = 200;
        container()->feedbackLoop->actions = [];
        container()->feedbackLoop->subscriberAction = 'unsubscribe';
        container()->feedbackLoop->canAct = true;
        container()->feedbackLoop->persistSubscriberStatus = true;
        container()->feedbackLoop->recordUnsubscribeTrack = true;
        container()->feedbackLoop->recordComplaint = true;
        hooks()->actions = [];
        app()->endRequestHandlers->handlers = [];
        mutex()->acquireResult = true;
        mutex()->releaseResult = true;
        mutex()->acquireResults = [];
        mutex()->acquired = [];
        mutex()->released = [];
        FakeDb::$events = [];
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
        CampaignDeliveryLog::$records[] = [
            'campaign_id' => 17,
            'subscriber_id' => 37,
            'server_id' => 7,
        ];

        FakeRequest::$rawBody = $body;
        FakeRequest::$server['CONTENT_LENGTH'] = (string)strlen($body);
        FakeRequest::$server['HTTP_X_SENDMUX_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', $body, $secret);

        (new SendmuxExtFrontendDswhController())->actionIndex(7);

        self::assertCount(1, CampaignBounceLog::$saved);
        self::assertSame(CampaignBounceLog::BOUNCE_HARD, CampaignBounceLog::$saved[0]->bounce_type);
        self::assertSame('Bounce: Permanent - General', CampaignBounceLog::$saved[0]->message);
        self::assertCount(1, $subscriber->blacklistMessages);
        self::assertSame('Bounce: Permanent - General', $subscriber->blacklistMessages[0]);
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
        FakeRequest::$server['CONTENT_LENGTH'] = (string)strlen($body);
        FakeRequest::$server['HTTP_X_SENDMUX_SIGNATURE'] = 'sha256=' . str_repeat('0', 64);

        (new SendmuxExtFrontendDswhController())->actionIndex(7);

        self::assertSame([], CampaignBounceLog::$saved);
        self::assertSame([], $subscriber->blacklistMessages);
        self::assertSame(401, Response::$statusCode);
    }

    public function testDeclaredOversizedWebhookIsRejectedBeforeBodyRead(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        $body = '{}';
        FakeRequest::$rawBody = $body;
        FakeRequest::$server['CONTENT_LENGTH'] = (string)(SendmuxExtFrontendDswhController::MAX_WEBHOOK_BODY_BYTES + 1);
        FakeRequest::$server['HTTP_X_SENDMUX_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', $body, $secret);

        (new SendmuxExtFrontendDswhController())->actionIndex(7);

        self::assertSame(413, Response::$statusCode);
        self::assertSame(0, FakeRequest::$rawBodyCalls);
        self::assertSame([], CampaignBounceLog::$saved);
        self::assertSame([], $subscriber->blacklistMessages);
    }

    public function testDeclaredWebhookAtMaximumSizeReachesBodyRead(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);
        $body = '{}';
        FakeRequest::$rawBody = $body;
        FakeRequest::$server['CONTENT_LENGTH'] = (string)SendmuxExtFrontendDswhController::MAX_WEBHOOK_BODY_BYTES;
        FakeRequest::$server['HTTP_X_SENDMUX_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', $body, $secret);

        (new SendmuxExtFrontendDswhController())->actionIndex(7);

        self::assertSame(200, Response::$statusCode);
        self::assertSame(1, FakeRequest::$rawBodyCalls);
    }

    public function testMissingContentLengthIsRejectedBeforeBodyRead(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);
        $body = '{}';
        FakeRequest::$rawBody = $body;
        FakeRequest::$server['HTTP_X_SENDMUX_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', $body, $secret);

        (new SendmuxExtFrontendDswhController())->actionIndex(7);

        self::assertSame(411, Response::$statusCode);
        self::assertSame(0, FakeRequest::$rawBodyCalls);
    }

    public function testInvalidContentLengthIsRejectedBeforeBodyRead(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);
        $body = '{}';
        FakeRequest::$rawBody = $body;
        FakeRequest::$server['CONTENT_LENGTH'] = 'not-a-number';
        FakeRequest::$server['HTTP_X_SENDMUX_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', $body, $secret);

        (new SendmuxExtFrontendDswhController())->actionIndex(7);

        self::assertSame(411, Response::$statusCode);
        self::assertSame(0, FakeRequest::$rawBodyCalls);
    }

    public function testActualOversizedWebhookIsRejectedAfterAFalseLengthDeclaration(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);
        $body = str_repeat('x', SendmuxExtFrontendDswhController::MAX_WEBHOOK_BODY_BYTES + 1);
        FakeRequest::$rawBody = $body;
        FakeRequest::$server['CONTENT_LENGTH'] = '1';
        FakeRequest::$server['HTTP_X_SENDMUX_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', $body, $secret);

        (new SendmuxExtFrontendDswhController())->actionIndex(7);

        self::assertSame(413, Response::$statusCode);
        self::assertSame(1, FakeRequest::$rawBodyCalls);
    }

    public function testSignedEventCannotMutateARecipientDeliveredByAnotherServer(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        CampaignDeliveryLog::$records[0]['server_id'] = 8;

        $this->sendSignedPayload([
            'id' => 'evt_wrong_delivery_server',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Permanent',
                'bounce_subtype' => 'General',
            ],
        ], $secret);

        self::assertSame([], CampaignBounceLog::$saved);
        self::assertSame([], $subscriber->blacklistMessages);
        self::assertSame(503, Response::$statusCode);
    }

    public function testArchivedDeliveryRecordAuthorisesSignedEvent(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        CampaignDeliveryLogArchive::$records = CampaignDeliveryLog::$records;
        CampaignDeliveryLog::$records = [];

        $this->sendSignedPayload([
            'id' => 'evt_archived_delivery',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Transient',
                'bounce_subtype' => 'General',
            ],
        ], $secret);

        self::assertCount(1, CampaignBounceLog::$saved);
        self::assertSame(CampaignBounceLog::BOUNCE_SOFT, CampaignBounceLog::$saved[0]->bounce_type);
        self::assertSame([], $subscriber->blacklistMessages);
        self::assertSame(200, Response::$statusCode);
    }

    public function testBounceStateTransitionUsesCampaignSubscriberMutex(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);

        $this->sendSignedPayload([
            'id' => 'evt_serialised_bounce',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Transient',
            ],
        ], $secret);

        $mutexKey = sha1('sendmux:webhook:bounce:17:37');
        self::assertSame([$mutexKey], mutex()->acquired);
        self::assertSame([$mutexKey], mutex()->released);
        self::assertCount(1, CampaignBounceLog::$saved);
    }

    public function testBounceTransactionCommitsBeforeCampaignSubscriberMutexRelease(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);

        $this->sendSignedPayload([
            'id' => 'evt_serialised_primary_bounce',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Transient',
            ],
        ], $secret);

        $mutexKey = sha1('sendmux:webhook:bounce:17:37');
        self::assertSame([
            'mutex.acquire:' . $mutexKey,
            'transaction.begin',
            'bounce.find',
            'bounce.save',
            'transaction.commit',
            'mutex.release:' . $mutexKey,
        ], FakeDb::$events);
    }

    public function testBounceTransactionRollsBackBeforeCampaignSubscriberMutexRelease(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);
        CampaignBounceLog::$saveResult = false;

        $this->sendSignedPayload([
            'id' => 'evt_failed_serialised_primary_bounce',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Transient',
            ],
        ], $secret);

        $mutexKey = sha1('sendmux:webhook:bounce:17:37');
        self::assertSame([
            'mutex.acquire:' . $mutexKey,
            'transaction.begin',
            'bounce.find',
            'bounce.save',
            'transaction.rollback',
            'mutex.release:' . $mutexKey,
        ], FakeDb::$events);
        self::assertSame(503, Response::$statusCode);
    }

    public function testBounceMutexReleaseFailureIsLogged(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);
        mutex()->releaseResult = false;

        $this->sendSignedPayload([
            'id' => 'evt_failed_bounce_mutex_release',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Transient',
            ],
        ], $secret);

        self::assertStringContainsString(
            'bounce lock release failed',
            implode('\n', array_column(Yii::$logs, 'message'))
        );
        self::assertSame(200, Response::$statusCode);
    }

    public function testBounceMutexContentionReturnsRetryableFailure(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);
        mutex()->acquireResult = false;

        $this->sendSignedPayload([
            'id' => 'evt_contended_bounce',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Transient',
            ],
        ], $secret);

        self::assertSame([sha1('sendmux:webhook:bounce:17:37')], mutex()->acquired);
        self::assertSame([], mutex()->released);
        self::assertSame([], CampaignBounceLog::$saved);
        self::assertSame(503, Response::$statusCode);
    }

    public function testSignedComplaintIsProcessedAfterAnEarlierBounce(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);

        $this->sendSignedPayload([
            'id' => 'evt_hard_before_complaint',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Permanent',
                'bounce_subtype' => 'General',
            ],
        ], $secret);

        self::assertSame(ListSubscriber::STATUS_BLACKLISTED, $subscriber->status);

        $this->sendSignedPayload([
            'id' => 'evt_current_complaint',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertCount(1, container()->feedbackLoop->actions);
        self::assertCount(1, $subscriber->blacklistMessages);
        self::assertSame(ListSubscriber::STATUS_UNSUBSCRIBED, $subscriber->status);
    }

    public function testComplaintProcessingUsesCampaignSubscriberMutexThroughDurableVerification(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);

        $this->sendSignedPayload([
            'id' => 'evt_serialised_complaint',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        $mutexKey = sha1('sendmux:webhook:complaint:17:37');
        self::assertSame($mutexKey, mutex()->acquired[0]);
        self::assertSame($mutexKey, mutex()->released[array_key_last(mutex()->released)]);
        self::assertSame(200, Response::$statusCode);
    }

    public function testComplaintMutexContentionStopsBeforeSuppression(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        mutex()->acquireResult = false;

        $this->sendSignedPayload([
            'id' => 'evt_contended_complaint',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertSame([sha1('sendmux:webhook:complaint:17:37')], mutex()->acquired);
        self::assertSame([], mutex()->released);
        self::assertSame([], EmailBlacklist::$messages);
        self::assertSame([], $subscriber->blacklistMessages);
        self::assertSame([], container()->feedbackLoop->actions);
        self::assertSame(503, Response::$statusCode);
    }

    public function testComplaintUsesFreshUnsubscribedStateBeforeMailWizzFeedbackAction(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        ListSubscriber::$persistedStatus = ListSubscriber::STATUS_UNSUBSCRIBED;

        $this->sendSignedPayload([
            'id' => 'evt_stale_confirmed_complaint',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertSame(ListSubscriber::STATUS_UNSUBSCRIBED, $subscriber->status);
        self::assertSame([], container()->feedbackLoop->actions);
        self::assertSame(1, CampaignTrackUnsubscribe::$count);
        self::assertSame(200, Response::$statusCode);
    }

    public function testComplaintMutexReleaseFailureIsLogged(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);
        mutex()->releaseResult = false;

        $this->sendSignedPayload([
            'id' => 'evt_failed_complaint_mutex_release',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertStringContainsString(
            'complaint lock release failed',
            implode('\n', array_column(Yii::$logs, 'message'))
        );
        self::assertSame(200, Response::$statusCode);
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

    public function testReplayAfterSuccessfulHardBounceDoesNotRepeatStateChanges(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        $subscriber->status = ListSubscriber::STATUS_BLACKLISTED;
        $existingBounce = new CampaignBounceLog();
        $existingBounce->bounce_type = CampaignBounceLog::BOUNCE_HARD;
        CampaignBounceLog::$found = $existingBounce;

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
        self::assertSame(ListSubscriber::STATUS_BLACKLISTED, $subscriber->status);
    }

    public function testDuplicateHardBounceCompletesPreviouslyFailedBlacklist(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        $existingBounce = new CampaignBounceLog();
        $existingBounce->message = '550 mailbox unavailable';
        $existingBounce->bounce_type = CampaignBounceLog::BOUNCE_HARD;
        CampaignBounceLog::$found = $existingBounce;

        $this->sendSignedPayload([
            'id' => 'evt_retry_hard_bounce_blacklist',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Permanent',
            ],
        ], $secret);

        self::assertSame([], CampaignBounceLog::$saved);
        self::assertSame(['550 mailbox unavailable'], $subscriber->blacklistMessages);
        self::assertSame(ListSubscriber::STATUS_BLACKLISTED, $subscriber->status);
        self::assertSame(200, Response::$statusCode);
    }

    public function testSignedHardBounceUpgradesAnEarlierSoftBounceAndBlacklistsSubscriber(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);

        $existingBounce = new CampaignBounceLog();
        $existingBounce->campaign_id = 17;
        $existingBounce->subscriber_id = 37;
        $existingBounce->message = '451 mailbox temporarily unavailable';
        $existingBounce->bounce_type = CampaignBounceLog::BOUNCE_SOFT;
        CampaignBounceLog::$found = $existingBounce;

        $this->sendSignedPayload([
            'id' => 'evt_hard_after_soft',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Permanent',
                'bounce_subtype' => 'General',
                'diagnostic_code' => '550 mailbox unavailable',
            ],
        ], $secret);

        self::assertCount(1, CampaignBounceLog::$saved);
        self::assertSame(CampaignBounceLog::BOUNCE_HARD, CampaignBounceLog::$saved[0]->bounce_type);
        self::assertCount(1, $subscriber->blacklistMessages);
    }

    public function testSignedSoftBounceCannotDowngradeAnEarlierHardBounce(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);

        $existingBounce = new CampaignBounceLog();
        $existingBounce->campaign_id = 17;
        $existingBounce->subscriber_id = 37;
        $existingBounce->message = '550 mailbox unavailable';
        $existingBounce->bounce_type = CampaignBounceLog::BOUNCE_HARD;
        CampaignBounceLog::$found = $existingBounce;

        $this->sendSignedPayload([
            'id' => 'evt_soft_after_hard',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Transient',
                'bounce_subtype' => 'General',
                'diagnostic_code' => '451 try again later',
            ],
        ], $secret);

        self::assertSame([], CampaignBounceLog::$saved);
        self::assertSame(CampaignBounceLog::BOUNCE_HARD, $existingBounce->bounce_type);
        self::assertSame('550 mailbox unavailable', $existingBounce->message);
        self::assertSame(['550 mailbox unavailable'], $subscriber->blacklistMessages);
        self::assertSame(ListSubscriber::STATUS_BLACKLISTED, $subscriber->status);
    }

    public function testNormalisedInternalBounceCannotDowngradeAnEarlierSoftBounce(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);

        $existingBounce = new CampaignBounceLog();
        $existingBounce->campaign_id = 17;
        $existingBounce->subscriber_id = 37;
        $existingBounce->message = '451 mailbox temporarily unavailable';
        $existingBounce->bounce_type = CampaignBounceLog::BOUNCE_SOFT;
        CampaignBounceLog::$found = $existingBounce;

        $this->sendSignedPayload([
            'id' => 'evt_blocked_after_soft',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Permanent',
                'bounce_subtype' => 'Blocked',
            ],
        ], $secret);

        self::assertSame([], CampaignBounceLog::$saved);
        self::assertSame(CampaignBounceLog::BOUNCE_SOFT, $existingBounce->bounce_type);
        self::assertSame('451 mailbox temporarily unavailable', $existingBounce->message);
        self::assertSame([], $subscriber->blacklistMessages);
    }

    public function testNewHardBounceNormalisedToInternalDoesNotBlacklistSubscriber(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);

        $this->sendSignedPayload([
            'id' => 'evt_new_blocked_bounce',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Permanent',
                'bounce_subtype' => 'Blocked',
            ],
        ], $secret);

        self::assertCount(1, CampaignBounceLog::$saved);
        self::assertSame(CampaignBounceLog::BOUNCE_INTERNAL, CampaignBounceLog::$saved[0]->bounce_type);
        self::assertSame([], $subscriber->blacklistMessages);
    }

    public function testFailedHardBounceSaveCannotBlacklistSubscriber(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        CampaignBounceLog::$saveResult = false;

        $this->sendSignedPayload([
            'id' => 'evt_failed_hard_bounce_save',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Permanent',
                'bounce_subtype' => 'General',
            ],
        ], $secret);

        self::assertSame([], CampaignBounceLog::$saved);
        self::assertSame([], $subscriber->blacklistMessages);
        self::assertSame(503, Response::$statusCode);
    }

    public function testFailedNewHardBounceBlacklistReturnsRetryableFailure(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        $subscriber->blacklistResult = false;

        $this->sendSignedPayload([
            'id' => 'evt_failed_new_hard_bounce_blacklist',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Permanent',
                'bounce_subtype' => 'General',
            ],
        ], $secret);

        self::assertCount(1, CampaignBounceLog::$saved);
        self::assertSame(CampaignBounceLog::BOUNCE_HARD, CampaignBounceLog::$saved[0]->bounce_type);
        self::assertSame([], $subscriber->blacklistMessages);
        self::assertSame(ListSubscriber::STATUS_CONFIRMED, $subscriber->status);
        self::assertSame(503, Response::$statusCode);
    }

    public function testOptimisticHardBounceBlacklistWithoutDurableSuppressionReturnsRetryableFailure(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        $subscriber->blacklistOptimisticResult = true;
        EmailBlacklist::$addResult = false;

        $this->sendSignedPayload([
            'id' => 'evt_optimistic_hard_bounce_blacklist',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Permanent',
                'bounce_subtype' => 'General',
            ],
        ], $secret);

        self::assertCount(1, CampaignBounceLog::$saved);
        self::assertSame([], EmailBlacklist::$messages);
        self::assertSame(ListSubscriber::STATUS_CONFIRMED, ListSubscriber::$persistedStatus);
        self::assertSame(503, Response::$statusCode);
    }

    public function testFailedHardBounceUpgradeBlacklistReturnsRetryableFailure(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        $subscriber->blacklistResult = false;
        $existingBounce = new CampaignBounceLog();
        $existingBounce->message = '451 mailbox temporarily unavailable';
        $existingBounce->bounce_type = CampaignBounceLog::BOUNCE_SOFT;
        CampaignBounceLog::$found = $existingBounce;

        $this->sendSignedPayload([
            'id' => 'evt_failed_hard_bounce_upgrade_blacklist',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Permanent',
                'bounce_subtype' => 'General',
            ],
        ], $secret);

        self::assertCount(1, CampaignBounceLog::$saved);
        self::assertSame(CampaignBounceLog::BOUNCE_HARD, CampaignBounceLog::$saved[0]->bounce_type);
        self::assertSame([], $subscriber->blacklistMessages);
        self::assertSame(ListSubscriber::STATUS_CONFIRMED, $subscriber->status);
        self::assertSame(503, Response::$statusCode);
    }

    public function testFailedBounceUpgradeIsRetriedWithoutBlacklistingSubscriber(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        $existingBounce = new CampaignBounceLog();
        $existingBounce->campaign_id = 17;
        $existingBounce->subscriber_id = 37;
        $existingBounce->message = '451 mailbox temporarily unavailable';
        $existingBounce->bounce_type = CampaignBounceLog::BOUNCE_SOFT;
        CampaignBounceLog::$found = $existingBounce;
        CampaignBounceLog::$saveResult = false;

        $this->sendSignedPayload([
            'id' => 'evt_failed_bounce_upgrade',
            'type' => 'message.bounced',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'bounce_type' => 'Permanent',
                'bounce_subtype' => 'General',
            ],
        ], $secret);

        self::assertSame([], CampaignBounceLog::$saved);
        self::assertSame([], $subscriber->blacklistMessages);
        self::assertSame(503, Response::$statusCode);
    }

    public function testSignedComplaintBlacklistsBeforeDefaultFeedbackActionUnsubscribes(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);

        $this->sendSignedPayload([
            'id' => 'evt_default_complaint_action',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertSame(['Complaint: abuse'], $subscriber->blacklistMessages);
        self::assertSame(ListSubscriber::STATUS_UNSUBSCRIBED, $subscriber->status);
        self::assertCount(1, container()->feedbackLoop->actions);
    }

    public function testComplaintForAlreadyUnsubscribedSubscriberUsesEmailBlacklist(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        $subscriber->status = ListSubscriber::STATUS_UNSUBSCRIBED;
        ListSubscriber::$persistedStatus = ListSubscriber::STATUS_UNSUBSCRIBED;

        $this->sendSignedPayload([
            'id' => 'evt_unsubscribed_complaint',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertSame([[
            'email' => 'prospect@example.net',
            'message' => 'Complaint: abuse',
        ]], EmailBlacklist::$messages);
        self::assertSame(ListSubscriber::STATUS_UNSUBSCRIBED, $subscriber->status);
        self::assertSame([], container()->feedbackLoop->actions);
        self::assertSame(200, Response::$statusCode);
    }

    public function testFailedPrimaryUnsubscribeWriteReturnsRetryableFailure(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        container()->feedbackLoop->persistSubscriberStatus = false;

        $this->sendSignedPayload([
            'id' => 'evt_failed_primary_unsubscribe',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertSame(ListSubscriber::STATUS_UNSUBSCRIBED, $subscriber->status);
        self::assertSame(ListSubscriber::STATUS_CONFIRMED, ListSubscriber::$persistedStatus);
        self::assertSame(503, Response::$statusCode);
    }

    public function testFailedUnsubscribeTrackingSaveReturnsRetryableFailure(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);
        container()->feedbackLoop->recordUnsubscribeTrack = false;
        CampaignTrackUnsubscribe::$saveResult = false;

        $this->sendSignedPayload([
            'id' => 'evt_failed_unsubscribe_tracking',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertSame(ListSubscriber::STATUS_UNSUBSCRIBED, ListSubscriber::$persistedStatus);
        self::assertSame(0, CampaignTrackUnsubscribe::$count);
        self::assertSame(503, Response::$statusCode);
    }

    public function testMissingUnsubscribeTrackingRowIsRepairedBeforeSuccess(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);
        container()->feedbackLoop->recordUnsubscribeTrack = false;

        $this->sendSignedPayload([
            'id' => 'evt_repair_unsubscribe_tracking',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertSame(1, CampaignTrackUnsubscribe::$count);
        self::assertCount(1, CampaignTrackUnsubscribe::$saved);
        self::assertSame('Unsubscribed via Web Hook!', CampaignTrackUnsubscribe::$saved[0]->note);
        self::assertSame(200, Response::$statusCode);
    }

    public function testUnsubscribeTrackingRepairUsesSubscriberMutex(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);
        container()->feedbackLoop->recordUnsubscribeTrack = false;

        $this->sendSignedPayload([
            'id' => 'evt_serialised_unsubscribe_tracking',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        $complaintMutexKey = sha1('sendmux:webhook:complaint:17:37');
        $unsubscribeMutexKey = sha1('subscriber:unsubscribe:37');
        self::assertSame([$complaintMutexKey, $unsubscribeMutexKey], mutex()->acquired);
        self::assertSame([$unsubscribeMutexKey, $complaintMutexKey], mutex()->released);
        self::assertSame(1, CampaignTrackUnsubscribe::$count);
    }

    public function testUnsubscribeTransactionCommitsBeforeSubscriberMutexRelease(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);
        container()->feedbackLoop->recordUnsubscribeTrack = false;

        $this->sendSignedPayload([
            'id' => 'evt_serialised_unsubscribe_commit',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        $mutexKey = sha1('subscriber:unsubscribe:37');
        $mutexStart = array_search('mutex.acquire:' . $mutexKey, FakeDb::$events, true);
        self::assertIsInt($mutexStart);
        self::assertSame([
            'mutex.acquire:' . $mutexKey,
            'transaction.begin',
            'subscriber.find',
            'unsubscribe.count',
            'unsubscribe.save',
            'transaction.commit',
            'mutex.release:' . $mutexKey,
            'mutex.release:' . sha1('sendmux:webhook:complaint:17:37'),
        ], array_slice(FakeDb::$events, $mutexStart));
    }

    public function testUnsubscribeTransactionRollsBackBeforeSubscriberMutexRelease(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);
        container()->feedbackLoop->recordUnsubscribeTrack = false;
        CampaignTrackUnsubscribe::$saveResult = false;

        $this->sendSignedPayload([
            'id' => 'evt_serialised_unsubscribe_rollback',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        $mutexKey = sha1('subscriber:unsubscribe:37');
        $mutexStart = array_search('mutex.acquire:' . $mutexKey, FakeDb::$events, true);
        self::assertIsInt($mutexStart);
        self::assertSame([
            'mutex.acquire:' . $mutexKey,
            'transaction.begin',
            'subscriber.find',
            'unsubscribe.count',
            'unsubscribe.save',
            'transaction.rollback',
            'mutex.release:' . $mutexKey,
            'mutex.release:' . sha1('sendmux:webhook:complaint:17:37'),
        ], array_slice(FakeDb::$events, $mutexStart));
        self::assertSame(503, Response::$statusCode);
    }

    public function testUnsubscribeMutexReleaseFailureIsLogged(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);
        mutex()->releaseResult = false;

        $this->sendSignedPayload([
            'id' => 'evt_failed_unsubscribe_mutex_release',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertStringContainsString(
            'unsubscribe lock release failed',
            implode('\n', array_column(Yii::$logs, 'message'))
        );
        self::assertSame(200, Response::$statusCode);
    }

    public function testUnsubscribeTrackingMutexContentionReturnsRetryableFailure(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);
        container()->feedbackLoop->recordUnsubscribeTrack = false;
        $complaintMutexKey = sha1('sendmux:webhook:complaint:17:37');
        $unsubscribeMutexKey = sha1('subscriber:unsubscribe:37');
        mutex()->acquireResults = [
            $complaintMutexKey => true,
            $unsubscribeMutexKey => false,
        ];

        $this->sendSignedPayload([
            'id' => 'evt_contended_unsubscribe_tracking',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertSame([$complaintMutexKey, $unsubscribeMutexKey], mutex()->acquired);
        self::assertSame([$complaintMutexKey], mutex()->released);
        self::assertSame(0, CampaignTrackUnsubscribe::$count);
        self::assertSame(503, Response::$statusCode);
    }

    public function testConfiguredCustomerBlacklistFailureReturnsRetryableFailure(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        Campaign::$found->customer->canUseOwnBlacklist = true;
        container()->feedbackLoop->subscriberAction = 'blacklist';
        $subscriber->customerBlacklistResult = false;

        $this->sendSignedPayload([
            'id' => 'evt_failed_customer_blacklist',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertCount(1, EmailBlacklist::$messages);
        self::assertSame([], CustomerEmailBlacklist::$records);
        self::assertSame(503, Response::$statusCode);
    }

    public function testConfiguredCustomerBlacklistPersistenceIsAccepted(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);
        Campaign::$found->customer->canUseOwnBlacklist = true;
        container()->feedbackLoop->subscriberAction = 'blacklist';

        $this->sendSignedPayload([
            'id' => 'evt_customer_blacklist',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertCount(1, CustomerEmailBlacklist::$records);
        self::assertSame(47, CustomerEmailBlacklist::$records[0]['customer_id']);
        self::assertSame('prospect@example.net', CustomerEmailBlacklist::$records[0]['email']);
        self::assertSame(200, Response::$statusCode);
    }

    public function testFailedComplaintBlacklistingIsRetriedBeforeFeedbackIsRecorded(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        $subscriber->blacklistResult = false;

        $this->sendSignedPayload([
            'id' => 'evt_failed_complaint_blacklist',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertSame([], $subscriber->blacklistMessages);
        self::assertSame(ListSubscriber::STATUS_CONFIRMED, $subscriber->status);
        self::assertSame([], container()->feedbackLoop->actions);
        self::assertSame(0, CampaignComplainLog::$count);
        self::assertSame(503, Response::$statusCode);
    }

    public function testFailedComplaintFeedbackActionReturnsRetryableFailure(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        container()->feedbackLoop->canAct = false;

        $this->sendSignedPayload([
            'id' => 'evt_failed_complaint_feedback',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertSame(ListSubscriber::STATUS_BLACKLISTED, $subscriber->status);
        self::assertSame(0, CampaignComplainLog::$count);
        self::assertSame(503, Response::$statusCode);
    }

    public function testComplaintLogSaveFailureReturnsRetryableFailure(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        container()->feedbackLoop->recordComplaint = false;
        CampaignComplainLog::$saveResult = false;

        $this->sendSignedPayload([
            'id' => 'evt_failed_complaint_log',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertSame(ListSubscriber::STATUS_UNSUBSCRIBED, $subscriber->status);
        self::assertSame(0, CampaignComplainLog::$count);
        self::assertSame(503, Response::$statusCode);
    }

    public function testFailedDeleteComplaintActionReturnsRetryableFailure(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);
        container()->feedbackLoop->subscriberAction = 'delete';
        container()->feedbackLoop->canAct = false;

        $this->sendSignedPayload([
            'id' => 'evt_failed_complaint_delete',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertNotNull(ListSubscriber::$found);
        self::assertSame(503, Response::$statusCode);
    }

    public function testSuccessfulDeleteComplaintActionIsAccepted(): void
    {
        $secret = 'whsec_controller-test';
        $this->arrangeMatchedCampaign($secret);
        container()->feedbackLoop->subscriberAction = 'delete';

        $this->sendSignedPayload([
            'id' => 'evt_successful_complaint_delete',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertNull(ListSubscriber::$found);
        self::assertSame(200, Response::$statusCode);
    }

    public function testComplaintReplayRepairsIncompleteConfirmedState(): void
    {
        $secret = 'whsec_controller-test';
        $subscriber = $this->arrangeMatchedCampaign($secret);
        CampaignComplainLog::$count = 1;

        $this->sendSignedPayload([
            'id' => 'evt_duplicate_complaint',
            'type' => 'message.complained',
            'data' => [
                'sender' => 'bounce+CAMPAIGN1+SUBSCRIBER1@example.com',
                'complaint_type' => 'abuse',
            ],
        ], $secret);

        self::assertSame(['Complaint: abuse'], $subscriber->blacklistMessages);
        self::assertSame(ListSubscriber::STATUS_UNSUBSCRIBED, $subscriber->status);
        self::assertCount(1, container()->feedbackLoop->actions);
        self::assertSame(200, Response::$statusCode);
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
        $campaign->customer = new FakeCustomer();
        $campaign->customer->customer_id = 47;
        Campaign::$found = $campaign;

        $subscriber = new ListSubscriber();
        $subscriber->subscriber_id = 37;
        $subscriber->customer_id = 47;
        $subscriber->email = 'prospect@example.net';
        ListSubscriber::$found = $subscriber;
        ListSubscriber::$persistedStatus = ListSubscriber::STATUS_CONFIRMED;
        CampaignDeliveryLog::$records[] = [
            'campaign_id' => 17,
            'subscriber_id' => 37,
            'server_id' => 7,
        ];

        return $subscriber;
    }

    private function sendSignedPayload(array $payload, string $secret): void
    {
        $body = (string)json_encode($payload, JSON_UNESCAPED_SLASHES);
        FakeRequest::$rawBody = $body;
        FakeRequest::$server['CONTENT_LENGTH'] = (string)strlen($body);
        FakeRequest::$server['HTTP_X_SENDMUX_SIGNATURE'] = 'sha256=' . hash_hmac('sha256', $body, $secret);

        (new SendmuxExtFrontendDswhController())->actionIndex(7);
    }
}
