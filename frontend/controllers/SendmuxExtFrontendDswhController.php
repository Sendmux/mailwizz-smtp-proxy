<?php defined('MW_PATH') || exit('No direct script access allowed');

Yii::import('frontend.controllers.DswhController');

/**
 * Sendmux Sending API Webhook Controller
 *
 * Handles webhook events from Sendmux for bounce and complaint processing.
 *
 * @package MailWizz Extension
 * @author Sendmux Team <contact@sendmux.ai>
 * @link https://sendmux.ai
 * @copyright 2026 Sendmux
 * @license FSL-2.0 (Functional Source License 2.0)
 * @version 0.3.0
 */

class SendmuxExtFrontendDswhController extends DswhController
{
    /**
     * Webhook event types - Complaints
     */
    const EVENT_ABUSE_REPORT = 'incoming-report.abuse-report';
    const EVENT_FRAUD_REPORT = 'incoming-report.fraud-report';
    const EVENT_MESSAGE_COMPLAINED = 'message.complained';

    /**
     * Webhook event types - Bounces
     */
    const EVENT_DSN_PERM_FAIL = 'delivery.dsn-perm-fail';
    const EVENT_DSN_TEMP_FAIL = 'delivery.dsn-temp-fail';
    const EVENT_DELIVERY_FAILED = 'delivery.failed';
    const EVENT_MESSAGE_BOUNCED = 'message.bounced';

    /**
     * The extension instance
     * @var SendmuxWebApiExt
     */
    public $extension;

    /**
     * Webhook endpoint action
     *
     * @param int $id Delivery server ID
     * @return void
     */
    public function actionIndex($id): void
    {
        $server = DeliveryServer::model()->findByPk((int)$id);

        if (empty($server)) {
            app()->end();
            return;
        }

        if ($server->type === 'sendmux-web-api') {
            $types = DeliveryServer::getTypesMapping();
            $modelClass = $types[$server->type] ?? null;
            $server = $modelClass ? DeliveryServer::model($modelClass)->findByPk((int)$id) : null;

            if (empty($server)) {
                app()->end();
                return;
            }
        }

        $map = [
            'amazon-ses-web-api'   => [$this, 'processAmazonSes'],
            'mailgun-web-api'      => [$this, 'processMailgun'],
            'sendgrid-web-api'     => [$this, 'processSendgrid'],
            'elasticemail-web-api' => [$this, 'processElasticemail'],
            'dyn-web-api'          => [$this, 'processDyn'],
            'sparkpost-web-api'    => [$this, 'processSparkpost'],
            'mailjet-web-api'      => [$this, 'processMailjet'],
            'sendinblue-web-api'   => [$this, 'processSendinblue'],
            'tipimail-web-api'     => [$this, 'processTipimail'],
            'pepipost-web-api'     => [$this, 'processPepipost'],
            'postmark-web-api'     => [$this, 'processPostmark'],
            'sendmux-web-api'      => [$this, 'processSendmuxWebApi'],
        ];

        $map = (array)hooks()->applyFilters('dswh_process_map', $map, $server, $this);

        if (isset($map[$server->type]) && is_callable($map[$server->type])) {
            hooks()->doAction('dswh_before_process', new CAttributeCollection([
                'processor' => $server->type,
            ]));

            app()->getEventHandlers('onEndRequest')->insertAt(0, function () use ($server) {
                hooks()->doAction('dswh_after_process', new CAttributeCollection([
                    'processor' => $server->type,
                ]));
            });

            call_user_func_array($map[$server->type], [$server, $this]);
        }

        app()->end();
    }

    /**
     * Process Sendmux webhook events
     *
     * @param DeliveryServer $server
     * @return void
     */
    public function processSendmuxWebApi($server): void
    {
        // Read the exact raw JSON payload once through MailWizz's request object.
        $rawBody = (string)request()->getRawBody();

        if (empty($rawBody)) {
            Yii::log('Sendmux webhook received empty request body', 'error', 'sendmux.webhook');
            app()->end();
            return;
        }

        $signature = (string)request()->getServer('HTTP_X_SENDMUX_SIGNATURE', '');
        $webhookSecret = (string)$server->username;
        if (!SendmuxWebhookSignatureVerifier::verify($rawBody, $signature, $webhookSecret)) {
            Yii::log('Sendmux webhook signature verification failed', 'warning', 'sendmux.webhook');
            (new Response())->setStatusCode(401)->send();
            app()->end();
            return;
        }

        // Parse JSON payload
        $payload = json_decode($rawBody, true);

        if (!$payload) {
            Yii::log('Sendmux webhook failed to parse JSON', 'error', 'sendmux.webhook');
            app()->end();
            return;
        }

        // Support both single event and batch event formats
        $events = [];
        if (isset($payload['events']) && is_array($payload['events'])) {
            // Batch format: {"events": [...]}
            $events = $payload['events'];
        } elseif (isset($payload['type']) && isset($payload['data'])) {
            // Single event format: {id, type, data}
            $events = [$payload];
        } else {
            // Invalid format
            Yii::log('Sendmux webhook invalid event format: ' . json_encode($payload), 'error', 'sendmux.webhook');
            app()->end();
            return;
        }

        // Process each event in the payload
        foreach ($events as $event) {
            $this->processWebhookEvent($event);
        }

        app()->end();
        return;
    }

    /**
     * Process a single webhook event
     *
     * @param array $event
     * @return void
     */
    protected function processWebhookEvent(array $event): void
    {
        // Validate event structure
        if (!isset($event['type']) || !isset($event['data'])) {
            Yii::log('Sendmux webhook event missing type or data: ' . json_encode($event), 'error', 'sendmux.webhook');
            return;
        }

        $eventType = $event['type'];
        $eventData = $event['data'];

        // Current events expose the envelope sender as data.sender. Keep the
        // legacy data.from fallback for existing installations.
        $returnPath = $eventData['sender'] ?? $eventData['from'] ?? null;
        if (!is_string($returnPath) || $returnPath === '') {
            Yii::log('Sendmux webhook event missing sender field', 'error', 'sendmux.webhook');
            return;
        }

        // Parse return_path format
        // Campaign emails: bounce+{campaignUid}+{subscriberUid}@domain.com
        // Transactional emails: bounce@domain.com
        $atPos = strpos($returnPath, '@');
        if ($atPos === false) {
            Yii::log('Sendmux webhook invalid return path format: ' . $returnPath, 'error', 'sendmux.webhook');
            return;
        }

        $localPart = substr($returnPath, 0, $atPos);
        $parts = explode('+', $localPart);

        // Check if this is a campaign email (has UIDs) or transactional email (no UIDs)
        if (count($parts) < 3 || $parts[0] !== 'bounce') {
            // This is a transactional email (bounce@domain.com format)
            // Transactional emails don't have campaign/subscriber context, so we can't process them
            // Just log and return
            return;
        }

        $campaignUid = $parts[1];
        $subscriberUid = $parts[2];

        // Look up campaign
        $campaign = Campaign::model()->findByAttributes([
            'campaign_uid' => $campaignUid,
        ]);

        if (empty($campaign)) {
            Yii::log('Sendmux webhook campaign not found: ' . $campaignUid, 'warning', 'sendmux.webhook');
            return;
        }

        // Look up subscriber
        $subscriber = ListSubscriber::model()->findByAttributes([
            'list_id'        => $campaign->list_id,
            'subscriber_uid' => $subscriberUid,
            'status'         => ListSubscriber::STATUS_CONFIRMED,
        ]);

        if (empty($subscriber)) {
            Yii::log('Sendmux webhook subscriber not found: ' . $subscriberUid . ' for campaign: ' . $campaignUid, 'warning', 'sendmux.webhook');
            return;
        }

        // Determine if this is a complaint or bounce event
        $isComplaint = $this->isComplaintEvent($eventType);
        $bounceType = $this->getBounceType($eventType, $eventData);

        if (!$isComplaint && $bounceType !== null) {
            // Match MailWizz's provider handlers: deduplicate bounce logs, but
            // never let an earlier bounce suppress a later complaint.
            $existingBounce = CampaignBounceLog::model()->countByAttributes([
                'campaign_id'   => (int)$campaign->campaign_id,
                'subscriber_id' => (int)$subscriber->subscriber_id,
            ]);

            if (!empty($existingBounce)) {
                Yii::log('Sendmux webhook duplicate bounce ignored for campaign: ' . $campaignUid . ', subscriber: ' . $subscriberUid, 'info', 'sendmux.webhook');
                return;
            }
        }

        // Extract error/failure message
        $errorMessage = $this->extractErrorMessage($eventType, $eventData);

        // Process complaints
        if ($isComplaint) {
            /** @var OptionCronProcessFeedbackLoopServers $fbl */
            $fbl = container()->get(OptionCronProcessFeedbackLoopServers::class);
            $fbl->takeActionAgainstSubscriberWithCampaign($subscriber, $campaign);

            // Blacklist subscriber - store error message directly without prefix
            $subscriber->addToBlacklist($errorMessage);

            return;
        }

        // Process bounces
        if ($bounceType !== null) {
            $bounceLog = new CampaignBounceLog();
            $bounceLog->campaign_id   = (int)$campaign->campaign_id;
            $bounceLog->subscriber_id = (int)$subscriber->subscriber_id;
            $bounceLog->message       = $errorMessage;
            $bounceLog->bounce_type   = $bounceType;
            $bounceLog->save();

            // Blacklist on hard bounces only
            if ($bounceType === CampaignBounceLog::BOUNCE_HARD) {
                $subscriber->addToBlacklist($bounceLog->message);
            }
        }
    }

    /**
     * Check if event is a complaint
     *
     * @param string $eventType
     * @return bool
     */
    protected function isComplaintEvent(string $eventType): bool
    {
        $complaintEvents = [
            self::EVENT_ABUSE_REPORT,
            self::EVENT_FRAUD_REPORT,
            self::EVENT_MESSAGE_COMPLAINED,
        ];

        return in_array($eventType, $complaintEvents);
    }

    /**
     * Get bounce type based on event type
     *
     * @param string $eventType
     * @param array $eventData
     * @return string|null
     */
    protected function getBounceType(string $eventType, array $eventData = []): ?string
    {
        if ($eventType === self::EVENT_MESSAGE_BOUNCED) {
            $bounceTypes = [
                'Permanent'   => CampaignBounceLog::BOUNCE_HARD,
                'Transient'   => CampaignBounceLog::BOUNCE_SOFT,
                'Undetermined' => CampaignBounceLog::BOUNCE_INTERNAL,
            ];

            return $bounceTypes[$eventData['bounce_type'] ?? ''] ?? CampaignBounceLog::BOUNCE_INTERNAL;
        }

        $bounceMapping = [
            self::EVENT_DSN_PERM_FAIL    => CampaignBounceLog::BOUNCE_HARD,
            self::EVENT_DSN_TEMP_FAIL    => CampaignBounceLog::BOUNCE_SOFT,
            self::EVENT_DELIVERY_FAILED  => CampaignBounceLog::BOUNCE_INTERNAL,
        ];

        return $bounceMapping[$eventType] ?? null;
    }

    /**
     * Extract error message from event data based on event type
     *
     * @param string $eventType
     * @param array $eventData
     * @return string
     */
    protected function extractErrorMessage(string $eventType, array $eventData): string
    {
        if ($eventType === self::EVENT_MESSAGE_BOUNCED) {
            $parts = array_filter([
                $eventData['bounce_type'] ?? null,
                $eventData['bounce_subtype'] ?? null,
            ], 'is_string');

            return !empty($parts) ? 'Bounce: ' . implode(' - ', $parts) : 'Bounce received';
        }

        if ($eventType === self::EVENT_MESSAGE_COMPLAINED) {
            $parts = array_filter([
                $eventData['complaint_type'] ?? null,
                $eventData['complaint_subtype'] ?? null,
            ], 'is_string');

            return !empty($parts) ? 'Complaint: ' . implode(' - ', $parts) : 'Complaint received';
        }

        // For delivery.failed events, use 'reason' field
        if ($eventType === self::EVENT_DELIVERY_FAILED && isset($eventData['reason'])) {
            return $eventData['reason'];
        }

        // For DSN bounces, use 'details' field (contains full SMTP error)
        if (isset($eventData['details'])) {
            // Details can be a string or array
            if (is_array($eventData['details'])) {
                return implode('; ', $eventData['details']);
            }
            return $eventData['details'];
        }

        // For complaints, construct message from available data
        if ($this->isComplaintEvent($eventType)) {
            $parts = [];

            if (isset($eventData['hostname'])) {
                $parts[] = 'Reporter: ' . $eventData['hostname'];
            }

            if (isset($eventData['remoteIp'])) {
                $parts[] = 'IP: ' . $eventData['remoteIp'];
            }

            if (isset($eventData['result'])) {
                $parts[] = 'Result: ' . $eventData['result'];
            }

            return !empty($parts) ? implode(', ', $parts) : 'Complaint received';
        }

        // Fallback
        return 'No details provided';
    }
}
