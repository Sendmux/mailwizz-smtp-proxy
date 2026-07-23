<?php

declare(strict_types=1);

final class FakeHooks
{
    /** @var array<int, array{name: string, arguments: array}> */
    public array $actions = [];

    public function applyFilters(string $name, $value)
    {
        return $value;
    }

    public function doAction(string $name, ...$arguments): void
    {
        $this->actions[] = compact('name', 'arguments');
    }
}

function hooks(): FakeHooks
{
    static $hooks;

    return $hooks ??= new FakeHooks();
}

function t(string $category, string $message): string
{
    return $message;
}

class CMap
{
    public static function mergeArray(array $first, array $second): array
    {
        return array_replace_recursive($first, $second);
    }
}

class StringHelper
{
    public static function truncateLength(string $value, int $length): string
    {
        return substr($value, 0, $length);
    }
}

class CActiveForm
{
    public function passwordField($model, string $attribute, array $options = []): string
    {
        return sprintf('<input type="password" name="%s">', $attribute);
    }
}

final class FakeFieldDecorator
{
    public function getHtmlOptions(string $attribute, array $options = []): array
    {
        return $options;
    }
}

final class FakeRequest
{
    public static string $rawBody = '';
    public static int $rawBodyCalls = 0;

    /** @var array<string, string> */
    public static array $server = [];

    public function getRawBody(): string
    {
        self::$rawBodyCalls++;

        return self::$rawBody;
    }

    public function getServer(string $name, $defaultValue = null)
    {
        return self::$server[strtoupper($name)] ?? $defaultValue;
    }

    public function getUserHostAddress(): string
    {
        return '127.0.0.1';
    }

    public function getUserAgent(): string
    {
        return 'PHPUnit';
    }
}

function request(): FakeRequest
{
    static $request;

    return $request ??= new FakeRequest();
}

final class FakeTransaction
{
    private bool $active = true;

    public function __construct()
    {
        FakeDb::$events[] = 'transaction.begin';
    }

    public function commit(): void
    {
        FakeDb::$events[] = 'transaction.commit';
        $this->active = false;
    }

    public function rollback(): void
    {
        FakeDb::$events[] = 'transaction.rollback';
        $this->active = false;
    }

    public function getActive(): bool
    {
        return $this->active;
    }
}

final class FakeDb
{
    /** @var string[] */
    public static array $events = [];

    public function beginTransaction(): FakeTransaction
    {
        return new FakeTransaction();
    }
}

function db(): FakeDb
{
    static $db;

    return $db ??= new FakeDb();
}

final class FakeMutex
{
    public bool $acquireResult = true;
    public bool $releaseResult = true;

    /** @var array<string, bool> */
    public array $acquireResults = [];

    /** @var string[] */
    public array $acquired = [];

    /** @var string[] */
    public array $released = [];

    /** @var array<string, bool> */
    private array $active = [];

    public function acquire(string $name, int $timeout = 0): bool
    {
        $this->acquired[] = $name;
        FakeDb::$events[] = 'mutex.acquire:' . $name;
        if (!(array_key_exists($name, $this->acquireResults) ? $this->acquireResults[$name] : $this->acquireResult)) {
            return false;
        }

        $this->active[$name] = true;
        return true;
    }

    public function release(string $name): bool
    {
        $this->released[] = $name;
        FakeDb::$events[] = 'mutex.release:' . $name;
        unset($this->active[$name]);

        return $this->releaseResult;
    }

    public function isAcquired(string $name): bool
    {
        return isset($this->active[$name]);
    }
}

function mutex(): FakeMutex
{
    static $mutex;

    return $mutex ??= new FakeMutex();
}

function is_cli(): bool
{
    return false;
}

final class FakeApplication
{
    public int $endCalls = 0;
    public FakeEventHandlers $endRequestHandlers;

    public function __construct()
    {
        $this->endRequestHandlers = new FakeEventHandlers();
    }

    public function end(): void
    {
        $this->endCalls++;
    }

    public function getEventHandlers(string $name): FakeEventHandlers
    {
        return $this->endRequestHandlers;
    }
}

final class FakeEventHandlers
{
    /** @var callable[] */
    public array $handlers = [];

    public function insertAt(int $index, callable $handler): void
    {
        array_splice($this->handlers, $index, 0, [$handler]);
    }
}

function app(): FakeApplication
{
    static $app;

    return $app ??= new FakeApplication();
}

class Yii
{
    /** @var array<int, array{message: string, level: string, category: string}> */
    public static array $logs = [];

    public static function import(string $alias): void
    {
    }

    public static function log(string $message, string $level = 'info', string $category = 'application'): void
    {
        self::$logs[] = compact('message', 'level', 'category');
    }
}

class DswhController
{
}

class ExtensionInit
{
    public function beforeEnable()
    {
        return true;
    }

    public function afterDelete()
    {
    }
}

final class FakeDeliveryServerFinder
{
    private string $className;

    public function __construct(string $className)
    {
        $this->className = $className;
    }

    public function findByPk(int $id): ?DeliveryServer
    {
        $server = $this->className === DeliveryServer::class
            ? DeliveryServer::$found
            : DeliveryServer::$typedFound;

        return $server !== null && (int)$server->server_id === $id ? $server : null;
    }
}

class DeliveryServer
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    public static ?DeliveryServer $found = null;
    public static ?DeliveryServer $typedFound = null;

    public int $server_id = 0;
    public string $type = '';
    public ?string $username = null;
    public string $password = '';
    public string $from_email = '';
    public string $from_name = '';
    public string $status = self::STATUS_ACTIVE;
    public int $timeout = 30;
    public bool $isNewRecord = true;

    public FakeFieldDecorator $fieldDecorator;
    public FakeMailer $mailer;
    public int $usageLogs = 0;

    /** @var array<string, string[]> */
    public array $errors = [];

    public function __construct()
    {
        $this->fieldDecorator = new FakeFieldDecorator();
        $this->mailer = new FakeMailer();
    }

    public function behaviors()
    {
        return [
            'passwordHandler' => [
                'class' => 'common.components.db.behaviors.RemoteServerPasswordHandlerBehavior',
            ],
        ];
    }

    public function rules()
    {
        return [];
    }

    protected function beforeValidate()
    {
        return true;
    }

    protected function afterValidate()
    {
        if (!$this->getIsNewRecord() && !is_cli()) {
            $stored = self::model()->findByPk($this->server_id);
            if ($stored !== null && (string)$stored->username !== (string)$this->username) {
                $this->status = self::STATUS_INACTIVE;
            }
        }
    }

    public function getIsNewRecord(): bool
    {
        return $this->isNewRecord;
    }

    public function addError(string $attribute, string $message): void
    {
        $this->errors[$attribute][] = $message;
    }

    public function getFormFieldsDefinition(array $fields = []): array
    {
        return array_filter($fields, static function ($field): bool {
            return is_array($field) && !empty($field);
        });
    }

    public function getParamsArray(array $params = []): array
    {
        return $params;
    }

    public function getMailer(): FakeMailer
    {
        return $this->mailer;
    }

    public function parseHeadersIntoKeyValue(array $headers): array
    {
        return $headers;
    }

    public function logUsage(): void
    {
        $this->usageLogs++;
    }

    public static function model($className = self::class)
    {
        return new FakeDeliveryServerFinder((string)$className);
    }

    public static function getTypesMapping(): array
    {
        return [
            'sendmux-web-api' => 'DeliveryServerSendmuxWebApi',
        ];
    }
}

final class FakeMailer
{
    /** @var string[] */
    public array $logs = [];

    public function findEmailAndName(string $address): array
    {
        return [$address, null];
    }

    public function addLog(string $message): void
    {
        $this->logs[] = $message;
    }
}

class ArrayHelper
{
    public static function hasKeys(array $data, array $keys): bool
    {
        return count(array_diff($keys, array_keys($data))) === 0;
    }
}

class CampaignHelper
{
    public static function htmlToText(string $html): string
    {
        return strip_tags($html);
    }
}

class Campaign
{
    public static ?Campaign $found = null;

    public int $campaign_id = 0;
    public int $list_id = 0;
    public ?FakeCustomer $customer = null;

    public static function model(): Campaign
    {
        return new self();
    }

    public function findByAttributes(array $attributes): ?Campaign
    {
        return self::$found;
    }
}

final class FakeCustomer
{
    public int $customer_id = 0;
    public bool $canUseOwnBlacklist = false;

    public function getGroupOption(string $option, $defaultValue = null)
    {
        return $option === 'lists.can_use_own_blacklist' && $this->canUseOwnBlacklist ? 'yes' : $defaultValue;
    }
}

class CampaignDeliveryLog
{
    /** @var array<int, array{campaign_id: int, subscriber_id: int, server_id: int}> */
    public static array $records = [];

    public static function model(): CampaignDeliveryLog
    {
        return new static();
    }

    public function countByAttributes(array $attributes): int
    {
        return count(array_filter(static::$records, static function (array $record) use ($attributes): bool {
            foreach ($attributes as $attribute => $value) {
                if (!array_key_exists($attribute, $record) || $record[$attribute] !== $value) {
                    return false;
                }
            }

            return true;
        }));
    }
}

class CampaignDeliveryLogArchive extends CampaignDeliveryLog
{
    /** @var array<int, array{campaign_id: int, subscriber_id: int, server_id: int}> */
    public static array $records = [];
}

class EmailBlacklist
{
    public const ABUSE_COMPLAINT_REASON = 'Abuse complaint!';

    public static bool $addResult = true;

    /** @var array<int, array{email: string, message: string}> */
    public static array $messages = [];

    public string $email = '';

    public static function model(): EmailBlacklist
    {
        return new self();
    }

    public function findByEmail(string $email): ?EmailBlacklist
    {
        foreach (self::$messages as $record) {
            if ($record['email'] === $email) {
                $model = new self();
                $model->email = $email;

                return $model;
            }
        }

        return null;
    }

    public static function addToBlacklist($subscriber, string $message = ''): bool
    {
        if (!self::$addResult || !is_string($subscriber)) {
            return false;
        }

        self::$messages[] = [
            'email' => $subscriber,
            'message' => $message,
        ];

        return true;
    }
}

class ListSubscriber
{
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_UNSUBSCRIBED = 'unsubscribed';
    public const STATUS_BLACKLISTED = 'blacklisted';

    public static ?ListSubscriber $found = null;
    public static ?string $persistedStatus = null;

    public int $subscriber_id = 0;
    public int $customer_id = 0;
    public string $email = '';
    public string $status = self::STATUS_CONFIRMED;
    public bool $blacklistResult = true;
    public bool $blacklistOptimisticResult = false;
    public bool $customerBlacklistResult = true;

    /** @var string[] */
    public array $blacklistMessages = [];

    public static function model(): ListSubscriber
    {
        return new self();
    }

    public function findByAttributes(array $attributes): ?ListSubscriber
    {
        if (self::$found !== null && isset($attributes['status']) && self::$found->status !== $attributes['status']) {
            return null;
        }

        return self::$found;
    }

    public function findByPk(int $id): ?ListSubscriber
    {
        FakeDb::$events[] = 'subscriber.find';
        if (self::$found === null || self::$found->subscriber_id !== $id) {
            return null;
        }

        $storedSubscriber = clone self::$found;
        if (self::$persistedStatus !== null) {
            $storedSubscriber->status = self::$persistedStatus;
        }

        return $storedSubscriber;
    }

    public function refresh(): bool
    {
        $storedSubscriber = $this->findByPk($this->subscriber_id);
        if ($storedSubscriber === null) {
            return false;
        }

        $this->status = $storedSubscriber->status;
        return true;
    }

    public function addToBlacklist(string $message = ''): bool
    {
        if (!$this->getIsConfirmed() || !$this->blacklistResult) {
            return false;
        }

        $this->blacklistMessages[] = $message;
        $this->status = self::STATUS_BLACKLISTED;

        if ($this->blacklistOptimisticResult) {
            return true;
        }

        if (!EmailBlacklist::addToBlacklist($this->email, $message)) {
            $this->status = self::STATUS_CONFIRMED;
            return false;
        }

        return true;
    }

    public function addToCustomerBlacklist(string $message = ''): bool
    {
        if (!$this->customerBlacklistResult || !CustomerEmailBlacklist::$saveResult) {
            return false;
        }

        CustomerEmailBlacklist::$records[] = [
            'customer_id' => $this->customer_id,
            'email' => $this->email,
            'message' => $message,
        ];
        $this->status = self::STATUS_BLACKLISTED;

        return true;
    }

    public function getIsConfirmed(): bool
    {
        return $this->status === self::STATUS_CONFIRMED;
    }

    public function getIsUnsubscribed(): bool
    {
        return $this->status === self::STATUS_UNSUBSCRIBED;
    }
}

class CustomerEmailBlacklist
{
    public static bool $saveResult = true;

    /** @var array<int, array{customer_id: int, email: string, message: string}> */
    public static array $records = [];

    public static function findByEmailWithCustomerId(string $email, int $customerId): ?CustomerEmailBlacklist
    {
        foreach (self::$records as $record) {
            if ($record['customer_id'] === $customerId && $record['email'] === $email) {
                return new self();
            }
        }

        return null;
    }
}

class CampaignBounceLog
{
    public const BOUNCE_HARD = 'hard';
    public const BOUNCE_SOFT = 'soft';
    public const BOUNCE_INTERNAL = 'internal';

    public static ?CampaignBounceLog $found = null;
    public static bool $saveResult = true;

    /** @var CampaignBounceLog[] */
    public static array $saved = [];

    public int $campaign_id = 0;
    public int $subscriber_id = 0;
    public string $message = '';
    public string $bounce_type = '';

    public static function model(): CampaignBounceLog
    {
        return new self();
    }

    public function findByAttributes(array $attributes): ?CampaignBounceLog
    {
        FakeDb::$events[] = 'bounce.find';
        return self::$found;
    }

    public function looksLikeInternalBounce(): bool
    {
        return $this->bounce_type === self::BOUNCE_INTERNAL
            || preg_match('/unsolicited mail|(spam|block(ed)?)|(DNSBL|RBL|CDRBL|Blacklist)/i', $this->message) === 1;
    }

    public function save(): bool
    {
        FakeDb::$events[] = 'bounce.save';
        if ($this->looksLikeInternalBounce()) {
            $this->bounce_type = self::BOUNCE_INTERNAL;
        }

        if (!self::$saveResult) {
            return false;
        }

        self::$saved[] = clone $this;

        return true;
    }
}

class OptionCronProcessFeedbackLoopServers
{
}

class CampaignComplainLog
{
    public static int $count = 0;
    public static bool $saveResult = true;

    /** @var CampaignComplainLog[] */
    public static array $saved = [];

    public int $campaign_id = 0;
    public int $subscriber_id = 0;
    public string $message = '';

    public static function model(): CampaignComplainLog
    {
        return new self();
    }

    public function countByAttributes(array $attributes): int
    {
        return self::$count;
    }

    public function save(bool $runValidation = true): bool
    {
        if (!self::$saveResult) {
            return false;
        }

        self::$count = 1;
        self::$saved[] = clone $this;

        return true;
    }
}

class CampaignTrackUnsubscribe
{
    public static int $count = 0;
    public static bool $saveResult = true;

    /** @var CampaignTrackUnsubscribe[] */
    public static array $saved = [];

    public int $campaign_id = 0;
    public int $subscriber_id = 0;
    public string $note = '';
    public string $ip_address = '';
    public string $user_agent = '';

    public static function model(): CampaignTrackUnsubscribe
    {
        return new self();
    }

    public function countByAttributes(array $attributes): int
    {
        FakeDb::$events[] = 'unsubscribe.count';
        return self::$count;
    }

    public function save(bool $runValidation = true): bool
    {
        FakeDb::$events[] = 'unsubscribe.save';
        if (!self::$saveResult) {
            return false;
        }

        self::$count = 1;
        self::$saved[] = clone $this;

        return true;
    }
}

final class FakeFeedbackLoop
{
    /** @var array<int, array{0: ListSubscriber, 1: Campaign}> */
    public array $actions = [];
    public string $subscriberAction = 'unsubscribe';
    public bool $canAct = true;
    public bool $persistSubscriberStatus = true;
    public bool $recordUnsubscribeTrack = true;
    public bool $recordComplaint = true;

    public function getSubscriberActionIsDelete(): bool
    {
        return $this->subscriberAction === 'delete';
    }

    public function getSubscriberActionIsUnsubscribe(): bool
    {
        return $this->subscriberAction === 'unsubscribe';
    }

    public function getSubscriberActionIsBlacklist(): bool
    {
        return $this->subscriberAction === 'blacklist';
    }

    public function takeActionAgainstSubscriberWithCampaign(ListSubscriber $subscriber, Campaign $campaign): void
    {
        if (!$this->canAct) {
            return;
        }

        if ($this->getSubscriberActionIsUnsubscribe() && $subscriber->getIsUnsubscribed()) {
            return;
        }

        $this->actions[] = [$subscriber, $campaign];

        if ($this->getSubscriberActionIsDelete()) {
            ListSubscriber::$found = null;
            return;
        }

        if ($this->getSubscriberActionIsUnsubscribe()) {
            $subscriber->status = ListSubscriber::STATUS_UNSUBSCRIBED;
            if ($this->persistSubscriberStatus) {
                ListSubscriber::$persistedStatus = ListSubscriber::STATUS_UNSUBSCRIBED;
            }
            if ($this->recordUnsubscribeTrack) {
                CampaignTrackUnsubscribe::$count = 1;
            }
        }

        if ($this->getSubscriberActionIsBlacklist()) {
            if ($campaign->customer !== null && $campaign->customer->canUseOwnBlacklist) {
                $subscriber->addToCustomerBlacklist(EmailBlacklist::ABUSE_COMPLAINT_REASON);
            } else {
                $subscriber->addToBlacklist(EmailBlacklist::ABUSE_COMPLAINT_REASON);
            }
        }

        if ($this->recordComplaint) {
            CampaignComplainLog::$count = 1;
        }
    }
}

final class FakeContainer
{
    public FakeFeedbackLoop $feedbackLoop;

    public function __construct()
    {
        $this->feedbackLoop = new FakeFeedbackLoop();
    }

    public function get(string $className): FakeFeedbackLoop
    {
        return $this->feedbackLoop;
    }
}

function container(): FakeContainer
{
    static $container;

    return $container ??= new FakeContainer();
}

class CAttributeCollection
{
    public function __construct(array $attributes = [])
    {
    }
}

class Response
{
    public static int $statusCode = 200;

    public function setStatusCode(int $statusCode): Response
    {
        self::$statusCode = $statusCode;

        return $this;
    }

    public function send(): Response
    {
        return $this;
    }
}
