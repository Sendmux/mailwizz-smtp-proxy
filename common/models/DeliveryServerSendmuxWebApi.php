<?php

declare(strict_types=1);
if (!defined('MW_PATH')) {
    exit('No direct script access allowed');
}

/**
 * Sendmux Sending API Delivery Server Model
 *
 * Handles email sending via Sendmux Sending API with automatic bounce
 * and complaint handling through webhooks.
 *
 * @package MailWizz Extension
 * @author Sendmux Team <contact@sendmux.ai>
 * @link https://sendmux.ai
 * @copyright 2026 Sendmux
 * @license FSL-2.0 (Functional Source License 2.0)
 * @version 0.3.0
 */

class DeliveryServerSendmuxWebApi extends DeliveryServer
{
    /**
     * Sendmux Sending API endpoint
     */
    const EMAIL_PROXY_URL = 'https://smtp.sendmux.ai/api/v1/emails/send';

    /**
     * Sendmux API hostname
     */
    const EMAIL_PROXY_HOSTNAME = 'smtp.sendmux.ai';

    /**
     * Sendmux API URL for sending emails
     * @var string
     */
    public string $emailProxyUrl = self::EMAIL_PROXY_URL;

    /**
     * Write-only form field backed by the encrypted username column.
     *
     * @var string
     */
    public string $webhook_secret = '';

    /**
     * @var string
     */
    protected $serverType = 'sendmux-web-api';

    /**
     * @var string
     */
    protected $_providerUrl = 'https://sendmux.ai/';

    /**
     * Encrypt the webhook signing secret stored in the otherwise-unused
     * delivery-server username field.
     *
     * @return array
     */
    public function behaviors()
    {
        return CMap::mergeArray([
            'webhookSecretHandler' => [
                'class'         => 'common.components.db.behaviors.RemoteServerPasswordHandlerBehavior',
                'passwordField' => 'username',
            ],
        ], parent::behaviors());
    }

    /**
     * @return bool
     */
    protected function beforeValidate()
    {
        if ($this->webhook_secret !== '') {
            $webhookSecret = trim($this->webhook_secret);
            if (!$this->getIsNewRecord() && !is_cli() && $this->username !== $webhookSecret) {
                $this->status = self::STATUS_INACTIVE;
            }
            $this->username = $webhookSecret;
        }

        return parent::beforeValidate();
    }

    /**
     * Prevent MailWizz's generic credential comparison from treating the
     * encrypted stored username as a changed plaintext webhook secret.
     *
     * @return void
     */
    protected function afterValidate()
    {
        $webhookSecret = $this->username;

        if (!$this->getIsNewRecord() && !is_cli()) {
            $storedServer = DeliveryServer::model()->findByPk((int)$this->server_id);
            if (!empty($storedServer)) {
                $this->username = $storedServer->username;
            }
        }

        parent::afterValidate();
        $this->username = $webhookSecret;
    }

    /**
     * @return array
     */
    public function rules()
    {
        $rules = [
            ['password', 'required'],
            ['password', 'length', 'max' => 255],
            ['password', '_validateSendingKey'],
            ['webhook_secret', 'length', 'max' => 150],
            ['webhook_secret', '_validateWebhookSecret'],
        ];
        return CMap::mergeArray($rules, parent::rules());
    }

    /**
     * @param string $attribute
     * @return void
     */
    public function _validateSendingKey($attribute): void
    {
        if (!preg_match('/\Asmx_mbx_[A-Za-z0-9]{32}\z/', (string)$this->password)) {
            $this->addError($attribute, t('servers', 'Enter a send-capable Sendmux mailbox key (starts with smx_mbx_).'));
        }
    }

    /**
     * @param string $attribute
     * @return void
     */
    public function _validateWebhookSecret($attribute): void
    {
        if ((string)$this->username === '') {
            if ($this->getIsNewRecord()) {
                $this->status = self::STATUS_INACTIVE;
                return;
            }

            $this->addError($attribute, t('servers', 'Webhook signing secret is required.'));
            return;
        }

        if (!preg_match('/\Awhsec_[A-Za-z0-9_-]+\z/', (string)$this->username)) {
            $this->addError($attribute, t('servers', 'Enter the webhook signing secret shown by Sendmux (starts with whsec_).'));
        }
    }

    /**
     * @return array
     */
    public function attributeLabels()
    {
        $labels = [
            'password'       => t('servers', 'Sending Key'),
            'webhook_secret' => t('servers', 'Webhook signing secret'),
        ];
        return CMap::mergeArray(parent::attributeLabels(), $labels);
    }

    /**
     * @return array
     */
    public function attributeHelpTexts()
    {
        $texts = [
            'password'       => t('servers', 'Your send-capable Sendmux mailbox key (starts with smx_mbx_). Create it under API Keys in Sendmux.'),
            'webhook_secret' => t('servers', 'The reveal-once signing secret shown when you create the Sendmux webhook (starts with whsec_). Leave blank when editing to keep the saved secret.'),
        ];

        return CMap::mergeArray(parent::attributeHelpTexts(), $texts);
    }

    /**
     * @return array
     */
    public function attributePlaceholders()
    {
        $placeholders = [
            'password'       => t('servers', 'smx_mbx_...'),
            'webhook_secret' => t('servers', 'whsec_...'),
        ];

        return CMap::mergeArray(parent::attributePlaceholders(), $placeholders);
    }

    /**
     * Returns the static model of the specified AR class.
     * Please note that you should have this exact method in all your CActiveRecord descendants!
     * @param string $className active record class name.
     * @return DeliveryServerSendmuxWebApi the static model class
     */
    public static function model($className = __CLASS__)
    {
        /** @var DeliveryServerSendmuxWebApi $model */
        $model = parent::model($className);

        return $model;
    }

    /**
     * Get MIME type for file using finfo
     *
     * @param string $filePath
     * @return string
     */
    protected function getMimeType(string $filePath): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath);

        // Fallback to extension mapping if finfo fails
        if (!$mimeType) {
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeTypes = [
                'pdf'  => 'application/pdf',
                'doc'  => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xls'  => 'application/vnd.ms-excel',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'ppt'  => 'application/vnd.ms-powerpoint',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'gif'  => 'image/gif',
                'webp' => 'image/webp',
                'svg'  => 'image/svg+xml',
                'txt'  => 'text/plain',
                'csv'  => 'text/csv',
                'json' => 'application/json',
                'xml'  => 'application/xml',
            ];

            $mimeType = $mimeTypes[$extension] ?? 'application/octet-stream';
        }

        return $mimeType;
    }

    /**
     * Send email via Sendmux Sending API
     *
     * @param array $params
     * @return array
     * @throws CException
     */
    public function send(array $params = []): array
    {
        /** @var array $params */
        $params = (array)hooks()->applyFilters('delivery_server_before_send_email', $this->getParamsArray($params), $this);

        if (!ArrayHelper::hasKeys($params, ['from', 'to', 'subject', 'body'])) {
            return [];
        }

        [$fromEmail, $fromName] = $this->getMailer()->findEmailAndName($params['from']);
        [$toEmail, $toName]     = $this->getMailer()->findEmailAndName($params['to']);

        if (!empty($params['fromName'])) {
            $fromName = $params['fromName'];
        }

        $replyToEmail = $replyToName = null;
        if (!empty($params['replyTo'])) {
            [$replyToEmail, $replyToName] = $this->getMailer()->findEmailAndName($params['replyTo']);
        }

        $sent = [];

        try {
            $this->from_name = empty($this->from_name) ? $this->from_email : $this->from_name;

            // Build return_path
            // For campaigns: bounce+{campaignUid}+{subscriberUid}@{from_domain}
            // For transactional: bounce@{from_domain}
            $fromDomain = substr(strrchr($fromEmail, "@"), 1);
            if (!empty($params['campaignUid']) && !empty($params['subscriberUid'])) {
                $returnPath = sprintf('bounce+%s+%s@%s',
                    $params['campaignUid'],
                    $params['subscriberUid'],
                    $fromDomain
                );
            } else {
                // Transactional emails - use simple bounce address
                $returnPath = 'bounce@' . $fromDomain;
            }

            // Build base payload (Sendmux API format)
            $postData = [
                'to'          => [
                    'email' => $toEmail,
                    'name'  => $toName ?? $toEmail,
                ],
                'from'        => [
                    'email' => !empty($fromEmail) ? $fromEmail : $this->from_email,
                    'name'  => !empty($fromName) ? $fromName : $this->from_name,
                ],
                'reply_to'    => [
                    'email' => !empty($replyToEmail) ? $replyToEmail : $this->from_email,
                    'name'  => !empty($replyToName) ? $replyToName : $this->from_name,
                ],
                'subject'     => $params['subject'],
                'html_body'   => !empty($params['body']) ? $params['body'] : '',
                'text_body'   => !empty($params['plainText']) ? $params['plainText'] : CampaignHelper::htmlToText($params['body']),
                'return_path' => $returnPath,
            ];

            // Add custom headers (only X- prefixed headers)
            if (!empty($params['headers'])) {
                $customHeaders = [];
                $headers = $this->parseHeadersIntoKeyValue($params['headers']);
                foreach ($headers as $name => $value) {
                    // Only include headers starting with X- or x-
                    if (stripos($name, 'x-') === 0) {
                        $customHeaders[$name] = $value;
                    }
                }
                if (!empty($customHeaders)) {
                    $postData['custom_headers'] = $customHeaders;
                }
            }

            // Add attachments if present
            $onlyPlainText = !empty($params['onlyPlainText']) && $params['onlyPlainText'] === true;
            if (!$onlyPlainText && !empty($params['attachments']) && is_array($params['attachments'])) {
                $attachments = array_unique($params['attachments']);
                $postData['attachments'] = [];
                foreach ($attachments as $attachment) {
                    if (is_file($attachment)) {
                        $postData['attachments'][] = [
                            'filename' => basename($attachment),
                            'content'  => base64_encode((string)file_get_contents($attachment)),
                            'type'     => $this->getMimeType($attachment),
                        ];
                    }
                }
            }

            // Create Bearer Auth header (Sendmux API key)
            $authHeader = 'Bearer ' . $this->password;

            $requestHeaders = [
                'Content-Type'  => 'application/json',
                'Authorization' => $authHeader,
            ];
            if (!empty($params['campaignUid']) && !empty($params['subscriberUid'])) {
                $logicalSendId = implode("\0", [
                    (string)$params['campaignUid'],
                    (string)$params['subscriberUid'],
                ]);
                $requestHeaders['Idempotency-Key'] = 'mailwizz-' . hash('sha256', $logicalSendId);
            }

            // Send request to Sendmux API
            $response = (new GuzzleHttp\Client())->post($this->emailProxyUrl, [
                'headers'   => $requestHeaders,
                'timeout'   => (int)$this->timeout,
                'json'      => $postData,
            ]);

            $responseBody = (string)$response->getBody();
            $responseData = json_decode($responseBody, true);

            // Check for successful queuing (Sendmux API response format)
            if (isset($responseData['ok']) && $responseData['ok'] === true
                && isset($responseData['data']['status']) && $responseData['data']['status'] === 'queued') {
                $messageId = $responseData['data']['message_id'] ?? '';
                $this->getMailer()->addLog('OK - Message queued with ID: ' . $messageId);
                $sent = ['message_id' => $messageId];
            } else {
                throw new Exception('Unexpected response: ' . $responseBody);
            }
        } catch (GuzzleHttp\Exception\RequestException $e) {
            // Handle HTTP error responses
            $errorMessage = $e->getMessage();

            if ($e->hasResponse()) {
                $responseBody = (string)$e->getResponse()->getBody();
                $errorData = json_decode($responseBody, true);

                // Sendmux error format: {"ok":false,"error":{"code":"...","message":"...","param":"..."}}
                if (isset($errorData['error']['message'])) {
                    $errorMessage = $errorData['error']['message'];

                    // Add error code if available
                    if (isset($errorData['error']['code'])) {
                        $errorMessage = '[' . $errorData['error']['code'] . '] ' . $errorMessage;
                    }

                    // Add param if available (validation errors)
                    if (isset($errorData['error']['param'])) {
                        $errorMessage .= ' (field: ' . $errorData['error']['param'] . ')';
                    }
                }
            }

            $this->getMailer()->addLog($errorMessage);
        } catch (Exception $e) {
            $this->getMailer()->addLog($e->getMessage());
        }

        if ($sent) {
            $this->logUsage();
        }

        hooks()->doAction('delivery_server_after_send_email', $params, $this, $sent);

        return (array)$sent;
    }

    /**
     * @inheritDoc
     */
    public function getParamsArray(array $params = []): array
    {
        $params['transport'] = 'sendmux-web-api';
        return parent::getParamsArray($params);
    }

    /**
     * @inheritDoc
     */
    public function getFormFieldsDefinition(array $fields = []): array
    {
        $form = new CActiveForm();
        return parent::getFormFieldsDefinition(CMap::mergeArray([
            'username'                => null,
            'webhook_secret'          => [
                'visible'   => true,
                'fieldHtml' => $form->passwordField($this, 'webhook_secret', $this->fieldDecorator->getHtmlOptions('webhook_secret')),
            ],
            'hostname'                => null,
            'port'                    => null,
            'protocol'                => null,
            'timeout'                 => null,
            'signing_enabled'         => null,
            'max_connection_messages' => null,
            'bounce_server_id'        => null,
            'force_sender'            => null,
        ], $fields));
    }

    /**
     * Set default hostname after construct
     *
     * @return void
     */
    protected function afterConstruct()
    {
        parent::afterConstruct();
        $this->hostname = self::EMAIL_PROXY_HOSTNAME;
    }

    /**
     * @inheritDoc
     */
    public function getDswhUrl(): string
    {
        /** @var OptionUrl $optionUrl */
        $optionUrl = container()->get(OptionUrl::class);

        $url = $optionUrl->getFrontendUrl('dswh/sendmux-api/' . $this->server_id);
        if (is_cli()) {
            return $url;
        }
        if (request()->getIsSecureConnection() && parse_url($url, PHP_URL_SCHEME) == 'http') {
            $url = substr_replace($url, 'https', 0, 4);
        }
        return $url;
    }
}
