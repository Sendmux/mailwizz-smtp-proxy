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
    const MAX_WEBHOOK_BODY_BYTES = 1000000;

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
        $contentLength = (string)request()->getServer('CONTENT_LENGTH', '');
        if ($contentLength === '' || !ctype_digit($contentLength)) {
            Yii::log('Sendmux webhook request is missing a valid content length', 'warning', 'sendmux.webhook');
            (new Response())->setStatusCode(411)->send();
            app()->end();
            return;
        }

        if ((int)$contentLength > self::MAX_WEBHOOK_BODY_BYTES) {
            Yii::log('Sendmux webhook request body exceeds the allowed size', 'warning', 'sendmux.webhook');
            (new Response())->setStatusCode(413)->send();
            app()->end();
            return;
        }

        // Read the exact raw JSON payload once through MailWizz's request object.
        $rawBody = (string)request()->getRawBody();

        if (strlen($rawBody) > self::MAX_WEBHOOK_BODY_BYTES) {
            Yii::log('Sendmux webhook request body exceeds the allowed size', 'warning', 'sendmux.webhook');
            (new Response())->setStatusCode(413)->send();
            app()->end();
            return;
        }

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
            if ($this->processWebhookEvent($event, $server) === false) {
                (new Response())->setStatusCode(503)->send();
                app()->end();
                return;
            }
        }

        app()->end();
        return;
    }

    /**
     * Process a single webhook event
     *
     * @param array $event
     * @param DeliveryServer $server
     * @return bool|null False when Sendmux should retry the event
     */
    protected function processWebhookEvent(array $event, $server)
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

        // Determine if this is a complaint or bounce event before subscriber lookup.
        $isComplaint = $this->isComplaintEvent($eventType);
        $bounceType = $this->getBounceType($eventType, $eventData);
        $errorMessage = $this->extractErrorMessage($eventType, $eventData);

        // Look up subscriber
        $subscriberAttributes = [
            'list_id'        => $campaign->list_id,
            'subscriber_uid' => $subscriberUid,
        ];
        if (!$isComplaint) {
            $subscriberAttributes['status'] = ListSubscriber::STATUS_CONFIRMED;
        }
        $subscriber = ListSubscriber::model()->findByAttributes($subscriberAttributes);

        if (empty($subscriber)) {
            Yii::log('Sendmux webhook subscriber not found: ' . $subscriberUid . ' for campaign: ' . $campaignUid, 'warning', 'sendmux.webhook');
            return;
        }

        if ($isComplaint || $bounceType !== null) {
            $deliveryAttributes = [
                'campaign_id'   => (int)$campaign->campaign_id,
                'subscriber_id' => (int)$subscriber->subscriber_id,
                'server_id'     => (int)$server->server_id,
            ];
            $hasDelivery = CampaignDeliveryLog::model()->countByAttributes($deliveryAttributes)
                || CampaignDeliveryLogArchive::model()->countByAttributes($deliveryAttributes);

            if (!$hasDelivery) {
                Yii::log('Sendmux webhook delivery record not found for authenticated server', 'warning', 'sendmux.webhook');
                return false;
            }
        }

        if (!$isComplaint && $bounceType !== null) {
            return $this->processBounce(
                $campaign,
                $subscriber,
                $bounceType,
                $errorMessage,
                $campaignUid,
                $subscriberUid
            );
        }

        // Process complaints
        if ($isComplaint) {
            return $this->processComplaint(
                $campaign,
                $subscriber,
                $errorMessage,
                $campaignUid,
                $subscriberUid
            );
        }
    }

    /**
     * Persist one complaint action while preventing stale duplicate audit writes.
     *
     * @param Campaign $campaign
     * @param ListSubscriber $subscriber
     * @param string $errorMessage
     * @param string $campaignUid
     * @param string $subscriberUid
     * @return bool|null False when Sendmux should retry the event
     */
    protected function processComplaint(
        Campaign $campaign,
        ListSubscriber $subscriber,
        string $errorMessage,
        string $campaignUid,
        string $subscriberUid
    ) {
        $mutexKey = sha1(sprintf(
            'sendmux:webhook:complaint:%d:%d',
            (int)$campaign->campaign_id,
            (int)$subscriber->subscriber_id
        ));
        if (!mutex()->acquire($mutexKey, 10)) {
            Yii::log('Sendmux webhook complaint lock unavailable for campaign: ' . $campaignUid . ', subscriber: ' . $subscriberUid, 'warning', 'sendmux.webhook');
            return false;
        }

        $transaction = null;

        try {
            // Refresh on primary after acquiring the outer lock so MailWizz's
            // feedback action cannot act on a pre-lock replica snapshot.
            $transaction = db()->beginTransaction();
            if (!$subscriber->refresh()) {
                $transaction->rollback();
                Yii::log('Sendmux webhook complaint subscriber refresh failed for campaign: ' . $campaignUid . ', subscriber: ' . $subscriberUid, 'warning', 'sendmux.webhook');
                return false;
            }
            $transaction->commit();

            // Blacklist before MailWizz's default feedback action can change
            // a confirmed subscriber to unsubscribed.
            if (!$this->ensureSubscriberSuppressed($subscriber, $errorMessage)) {
                Yii::log('Sendmux webhook complaint blacklist failed for campaign: ' . $campaignUid . ', subscriber: ' . $subscriberUid, 'warning', 'sendmux.webhook');
                return false;
            }

            /** @var OptionCronProcessFeedbackLoopServers $fbl */
            $fbl = container()->get(OptionCronProcessFeedbackLoopServers::class);
            $fbl->takeActionAgainstSubscriberWithCampaign($subscriber, $campaign);

            if (!$this->feedbackActionPersisted($fbl, $subscriber, $campaign)) {
                Yii::log('Sendmux webhook complaint action failed for campaign: ' . $campaignUid . ', subscriber: ' . $subscriberUid, 'warning', 'sendmux.webhook');
                return false;
            }

            return;
        } catch (Exception $e) {
            if ($transaction !== null && $transaction->getActive()) {
                $transaction->rollback();
            }
            Yii::log('Sendmux webhook complaint processing failed for campaign: ' . $campaignUid . ', subscriber: ' . $subscriberUid, 'warning', 'sendmux.webhook');
            return false;
        } finally {
            if (!mutex()->release($mutexKey)) {
                Yii::log('Sendmux webhook complaint lock release failed for campaign: ' . $campaignUid . ', subscriber: ' . $subscriberUid, 'warning', 'sendmux.webhook');
            }
        }
    }

    /**
     * Persist one bounce transition while preserving severity under concurrency.
     *
     * @param Campaign $campaign
     * @param ListSubscriber $subscriber
     * @param string $bounceType
     * @param string $errorMessage
     * @param string $campaignUid
     * @param string $subscriberUid
     * @return bool|null False when Sendmux should retry the event
     */
    protected function processBounce(
        Campaign $campaign,
        ListSubscriber $subscriber,
        string $bounceType,
        string $errorMessage,
        string $campaignUid,
        string $subscriberUid
    ) {
        $mutexKey = sha1(sprintf(
            'sendmux:webhook:bounce:%d:%d',
            (int)$campaign->campaign_id,
            (int)$subscriber->subscriber_id
        ));
        if (!mutex()->acquire($mutexKey, 10)) {
            Yii::log('Sendmux webhook bounce lock unavailable for campaign: ' . $campaignUid . ', subscriber: ' . $subscriberUid, 'warning', 'sendmux.webhook');
            return false;
        }

        $transaction = null;

        try {
            // Keep the read/compare/write transition on primary storage and hold
            // the mutex until the transaction is durably committed or rolled back.
            $transaction = db()->beginTransaction();

            // Deduplicate repeat feedback while retaining the strongest bounce classification.
            $existingBounce = CampaignBounceLog::model()->findByAttributes([
                'campaign_id'   => (int)$campaign->campaign_id,
                'subscriber_id' => (int)$subscriber->subscriber_id,
            ]);

            if (!empty($existingBounce)) {
                $severity = [
                    CampaignBounceLog::BOUNCE_INTERNAL => 1,
                    CampaignBounceLog::BOUNCE_SOFT     => 2,
                    CampaignBounceLog::BOUNCE_HARD     => 3,
                ];

                $candidateBounce = new CampaignBounceLog();
                $candidateBounce->message     = $errorMessage;
                $candidateBounce->bounce_type = $bounceType;
                if ($candidateBounce->looksLikeInternalBounce()) {
                    $candidateBounce->bounce_type = CampaignBounceLog::BOUNCE_INTERNAL;
                }

                if ($severity[$candidateBounce->bounce_type] > $severity[$existingBounce->bounce_type]) {
                    $existingBounce->message     = $candidateBounce->message;
                    $existingBounce->bounce_type = $candidateBounce->bounce_type;

                    if (!$existingBounce->save()) {
                        $transaction->rollback();
                        Yii::log('Sendmux webhook bounce upgrade failed for campaign: ' . $campaignUid . ', subscriber: ' . $subscriberUid, 'warning', 'sendmux.webhook');
                        return false;
                    }

                    Yii::log('Sendmux webhook bounce upgraded for campaign: ' . $campaignUid . ', subscriber: ' . $subscriberUid, 'info', 'sendmux.webhook');
                } else {
                    Yii::log('Sendmux webhook duplicate bounce ignored for campaign: ' . $campaignUid . ', subscriber: ' . $subscriberUid, 'info', 'sendmux.webhook');
                }

                $bounceLog = $existingBounce;
            } else {
                $bounceLog = new CampaignBounceLog();
                $bounceLog->campaign_id   = (int)$campaign->campaign_id;
                $bounceLog->subscriber_id = (int)$subscriber->subscriber_id;
                $bounceLog->message       = $errorMessage;
                $bounceLog->bounce_type   = $bounceType;
                if (!$bounceLog->save()) {
                    $transaction->rollback();
                    Yii::log('Sendmux webhook bounce save failed for campaign: ' . $campaignUid . ', subscriber: ' . $subscriberUid, 'warning', 'sendmux.webhook');
                    return false;
                }
            }

            $transaction->commit();

            // Suppression uses its own primary-storage transaction, so start it
            // only after the bounce transition commits while retaining the mutex.
            if (
                $bounceLog->bounce_type === CampaignBounceLog::BOUNCE_HARD
                && !$this->ensureSubscriberSuppressed($subscriber, $bounceLog->message)
            ) {
                Yii::log('Sendmux webhook hard-bounce blacklist failed for campaign: ' . $campaignUid . ', subscriber: ' . $subscriberUid, 'warning', 'sendmux.webhook');
                return false;
            }

            return;
        } catch (Exception $e) {
            if ($transaction !== null && $transaction->getActive()) {
                $transaction->rollback();
            }
            Yii::log('Sendmux webhook bounce persistence failed for campaign: ' . $campaignUid . ', subscriber: ' . $subscriberUid, 'warning', 'sendmux.webhook');
            return false;
        } finally {
            if (!mutex()->release($mutexKey)) {
                Yii::log('Sendmux webhook bounce lock release failed for campaign: ' . $campaignUid . ', subscriber: ' . $subscriberUid, 'warning', 'sendmux.webhook');
            }
        }
    }

    /**
     * Apply subscriber suppression idempotently.
     *
     * @param ListSubscriber $subscriber
     * @param string $message
     * @return bool
     */
    protected function ensureSubscriberSuppressed(ListSubscriber $subscriber, string $message): bool
    {
        $transaction = null;

        try {
            // Verify suppression against primary storage because MailWizz can
            // report success after an unchecked subscriber-status update.
            $transaction = db()->beginTransaction();
            $storedSubscriber = ListSubscriber::model()->findByPk((int)$subscriber->subscriber_id);
            if (empty($storedSubscriber)) {
                $transaction->rollback();
                return false;
            }

            if ($this->subscriberSuppressionPersisted($storedSubscriber)) {
                $transaction->commit();
                return true;
            }

            $added = $subscriber->getIsConfirmed()
                ? $subscriber->addToBlacklist($message)
                : EmailBlacklist::addToBlacklist((string)$subscriber->email, $message);
            if (!$added) {
                $transaction->rollback();
                return false;
            }

            $storedSubscriber = ListSubscriber::model()->findByPk((int)$subscriber->subscriber_id);
            $persisted = !empty($storedSubscriber) && $this->subscriberSuppressionPersisted($storedSubscriber);

            // Some MailWizz configurations optimistically return true after an
            // unchecked status update. Fall back to the email overload, then
            // require a durable primary-storage postcondition.
            if (!$persisted) {
                if (!EmailBlacklist::addToBlacklist((string)$subscriber->email, $message)) {
                    $transaction->rollback();
                    return false;
                }
                $storedSubscriber = ListSubscriber::model()->findByPk((int)$subscriber->subscriber_id);
                $persisted = !empty($storedSubscriber) && $this->subscriberSuppressionPersisted($storedSubscriber);
            }

            if (!$persisted) {
                $transaction->rollback();
                return false;
            }

            $transaction->commit();
            return true;
        } catch (Exception $e) {
            if ($transaction !== null && $transaction->getActive()) {
                $transaction->rollback();
            }
            Yii::log('Sendmux webhook suppression persistence check failed', 'warning', 'sendmux.webhook');
            return false;
        }
    }

    /**
     * Check whether a global blacklist row or blacklisted subscriber state exists.
     *
     * @param ListSubscriber $subscriber
     * @return bool
     */
    protected function subscriberSuppressionPersisted(ListSubscriber $subscriber): bool
    {
        if ($subscriber->status === ListSubscriber::STATUS_BLACKLISTED) {
            return true;
        }

        return !empty(EmailBlacklist::model()->findByEmail((string)$subscriber->email));
    }

    /**
     * Verify the configured feedback action against primary storage.
     *
     * @param OptionCronProcessFeedbackLoopServers $fbl
     * @param ListSubscriber $subscriber
     * @param Campaign $campaign
     * @return bool
     */
    protected function feedbackActionPersisted($fbl, ListSubscriber $subscriber, Campaign $campaign): bool
    {
        $transaction = null;
        $mutexKey = null;

        if ($fbl->getSubscriberActionIsUnsubscribe()) {
            $mutexKey = sha1(sprintf('subscriber:unsubscribe:%s', $subscriber->subscriber_id));
            if (!mutex()->acquire($mutexKey, 10)) {
                return false;
            }
        }

        try {
            // A short transaction forces a primary read on installations with
            // read/write splitting, avoiding a false retry from replica lag.
            $transaction = db()->beginTransaction();
            $storedSubscriber = ListSubscriber::model()->findByPk((int)$subscriber->subscriber_id);

            if ($fbl->getSubscriberActionIsDelete()) {
                $persisted = empty($storedSubscriber);
            } elseif ($fbl->getSubscriberActionIsUnsubscribe()) {
                $persisted = !empty($storedSubscriber)
                    && $storedSubscriber->status === ListSubscriber::STATUS_UNSUBSCRIBED
                    && $this->ensureUnsubscribeTrack($campaign, $subscriber)
                    && $this->ensureComplaintLog($campaign, $subscriber);
            } elseif ($fbl->getSubscriberActionIsBlacklist()) {
                $persisted = !empty($storedSubscriber)
                    && $this->ensureConfiguredBlacklistPersisted($campaign, $storedSubscriber)
                    && $this->ensureComplaintLog($campaign, $subscriber);
            } else {
                $persisted = false;
            }

            if (!$persisted) {
                $transaction->rollback();
                return false;
            }

            $transaction->commit();
            return true;
        } catch (Exception $e) {
            if ($transaction !== null && $transaction->getActive()) {
                $transaction->rollback();
            }
            Yii::log('Sendmux webhook feedback persistence check failed', 'warning', 'sendmux.webhook');
            return false;
        } finally {
            if ($mutexKey !== null && !mutex()->release($mutexKey)) {
                Yii::log('Sendmux webhook unsubscribe lock release failed', 'warning', 'sendmux.webhook');
            }
        }
    }

    /**
     * Verify or repair the configured MailWizz blacklist record.
     *
     * @param Campaign $campaign
     * @param ListSubscriber $subscriber
     * @return bool
     */
    protected function ensureConfiguredBlacklistPersisted(Campaign $campaign, ListSubscriber $subscriber): bool
    {
        $customer = $campaign->customer;
        if (!empty($customer) && $customer->getGroupOption('lists.can_use_own_blacklist', 'no') === 'yes') {
            $customerId = (int)$customer->customer_id;
            if (!empty(CustomerEmailBlacklist::findByEmailWithCustomerId((string)$subscriber->email, $customerId))) {
                return true;
            }

            if (!$subscriber->addToCustomerBlacklist(EmailBlacklist::ABUSE_COMPLAINT_REASON)) {
                return false;
            }

            return !empty(CustomerEmailBlacklist::findByEmailWithCustomerId((string)$subscriber->email, $customerId));
        }

        return $this->subscriberSuppressionPersisted($subscriber);
    }

    /**
     * Persist MailWizz's unsubscribe audit row idempotently.
     *
     * @param Campaign $campaign
     * @param ListSubscriber $subscriber
     * @return bool
     */
    protected function ensureUnsubscribeTrack(Campaign $campaign, ListSubscriber $subscriber): bool
    {
        $attributes = [
            'campaign_id'   => (int)$campaign->campaign_id,
            'subscriber_id' => (int)$subscriber->subscriber_id,
        ];

        if (CampaignTrackUnsubscribe::model()->countByAttributes($attributes)) {
            return true;
        }

        $trackUnsubscribe = new CampaignTrackUnsubscribe();
        $trackUnsubscribe->campaign_id   = $attributes['campaign_id'];
        $trackUnsubscribe->subscriber_id = $attributes['subscriber_id'];
        $trackUnsubscribe->note          = 'Unsubscribed via Web Hook!';
        $trackUnsubscribe->ip_address    = (string)request()->getUserHostAddress();
        $trackUnsubscribe->user_agent    = StringHelper::truncateLength((string)request()->getUserAgent(), 255);

        return $trackUnsubscribe->save(false);
    }

    /**
     * Persist the complaint audit row idempotently.
     *
     * @param Campaign $campaign
     * @param ListSubscriber $subscriber
     * @return bool
     */
    protected function ensureComplaintLog(Campaign $campaign, ListSubscriber $subscriber): bool
    {
        $attributes = [
            'campaign_id'   => (int)$campaign->campaign_id,
            'subscriber_id' => (int)$subscriber->subscriber_id,
        ];
        if (CampaignComplainLog::model()->countByAttributes($attributes)) {
            return true;
        }

        $complaintLog = new CampaignComplainLog();
        $complaintLog->campaign_id   = $attributes['campaign_id'];
        $complaintLog->subscriber_id = $attributes['subscriber_id'];
        $complaintLog->message       = EmailBlacklist::ABUSE_COMPLAINT_REASON;

        return $complaintLog->save(false);
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
