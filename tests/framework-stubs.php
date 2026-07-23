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

    /** @var array<string, string> */
    public static array $server = [];

    public function getRawBody(): string
    {
        return self::$rawBody;
    }

    public function getServer(string $name, $defaultValue = null)
    {
        return self::$server[strtoupper($name)] ?? $defaultValue;
    }
}

function request(): FakeRequest
{
    static $request;

    return $request ??= new FakeRequest();
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

    public static function model(): Campaign
    {
        return new self();
    }

    public function findByAttributes(array $attributes): ?Campaign
    {
        return self::$found;
    }
}

class ListSubscriber
{
    public const STATUS_CONFIRMED = 'confirmed';

    public static ?ListSubscriber $found = null;

    public int $subscriber_id = 0;

    /** @var string[] */
    public array $blacklistMessages = [];

    public static function model(): ListSubscriber
    {
        return new self();
    }

    public function findByAttributes(array $attributes): ?ListSubscriber
    {
        return self::$found;
    }

    public function addToBlacklist(string $message): void
    {
        $this->blacklistMessages[] = $message;
    }
}

class CampaignBounceLog
{
    public const BOUNCE_HARD = 'hard';
    public const BOUNCE_SOFT = 'soft';
    public const BOUNCE_INTERNAL = 'internal';

    public static ?CampaignBounceLog $found = null;

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
        return self::$found;
    }

    public function save(): bool
    {
        self::$saved[] = clone $this;

        return true;
    }
}

class OptionCronProcessFeedbackLoopServers
{
}

final class FakeFeedbackLoop
{
    /** @var array<int, array{0: ListSubscriber, 1: Campaign}> */
    public array $actions = [];

    public function takeActionAgainstSubscriberWithCampaign(ListSubscriber $subscriber, Campaign $campaign): void
    {
        $this->actions[] = [$subscriber, $campaign];
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
