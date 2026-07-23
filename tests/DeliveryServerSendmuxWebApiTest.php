<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/framework-stubs.php';
require_once dirname(__DIR__) . '/common/models/DeliveryServerSendmuxWebApi.php';

final class DeliveryServerSendmuxWebApiTest extends TestCase
{
    protected function tearDown(): void
    {
        DeliveryServer::$found = null;
        DeliveryServer::$typedFound = null;
    }

    public function testWebhookSecretUsesMailWizzEncryptedCredentialBehaviour(): void
    {
        $server = new DeliveryServerSendmuxWebApi();
        $behaviours = $server->behaviors();

        self::assertArrayHasKey('webhookSecretHandler', $behaviours);
        self::assertSame('username', $behaviours['webhookSecretHandler']['passwordField']);
    }

    public function testSendingKeyRuleAcceptsMailboxKeyAndRejectsRootKey(): void
    {
        $server = new DeliveryServerSendmuxWebApi();
        $rules = $server->rules();
        $matchingRules = array_values(array_filter($rules, static function (array $rule): bool {
            return $rule[0] === 'password' && $rule[1] === '_validateSendingKey';
        }));

        self::assertCount(1, $matchingRules);

        $server->password = 'smx_root_0123456789ABCDEFGHIJKLMNOPQRSTUV';
        $server->_validateSendingKey('password');
        self::assertArrayHasKey('password', $server->errors);

        $validServer = new DeliveryServerSendmuxWebApi();
        $validServer->password = 'smx_mbx_0123456789ABCDEFGHIJKLMNOPQRSTUV';
        $validServer->_validateSendingKey('password');
        self::assertArrayNotHasKey('password', $validServer->errors);
    }

    public function testNewWebhookSecretMovesIntoEncryptedCredentialBeforeValidation(): void
    {
        self::assertTrue(property_exists(DeliveryServerSendmuxWebApi::class, 'webhook_secret'));

        $server = new DeliveryServerSendmuxWebApi();
        $server->username = null;
        $server->webhook_secret = 'whsec_new-secret';

        $beforeValidate = new ReflectionMethod($server, 'beforeValidate');
        $beforeValidate->setAccessible(true);
        $beforeValidate->invoke($server);

        self::assertSame('whsec_new-secret', $server->username);
    }

    public function testNewServerWithoutWebhookSecretRemainsInactiveUntilWebhookSetup(): void
    {
        $server = new DeliveryServerSendmuxWebApi();
        $server->isNewRecord = true;
        $server->status = DeliveryServer::STATUS_ACTIVE;
        $server->username = null;

        $server->_validateWebhookSecret('webhook_secret');

        self::assertArrayNotHasKey('webhook_secret', $server->errors);
        self::assertSame(DeliveryServer::STATUS_INACTIVE, $server->status);
    }

    public function testExistingServerWithoutWebhookSecretIsRejected(): void
    {
        $server = new DeliveryServerSendmuxWebApi();
        $server->isNewRecord = false;
        $server->username = '';

        $server->_validateWebhookSecret('webhook_secret');

        self::assertArrayHasKey('webhook_secret', $server->errors);
    }

    public function testWebhookSecretRuleRejectsWrongCredentialType(): void
    {
        $server = new DeliveryServerSendmuxWebApi();
        $rules = $server->rules();
        $matchingRules = array_values(array_filter($rules, static function (array $rule): bool {
            return $rule[0] === 'webhook_secret' && $rule[1] === '_validateWebhookSecret';
        }));

        self::assertCount(1, $matchingRules);

        $server->username = 'smx_mbx_not-a-webhook-secret';
        $server->_validateWebhookSecret('webhook_secret');

        self::assertArrayHasKey('webhook_secret', $server->errors);
    }

    public function testBlankSecretInputPreservesExistingEncryptedCredential(): void
    {
        $server = new DeliveryServerSendmuxWebApi();
        $server->username = 'whsec_existing-secret';
        $server->webhook_secret = '';

        $beforeValidate = new ReflectionMethod($server, 'beforeValidate');
        $beforeValidate->setAccessible(true);
        $beforeValidate->invoke($server);

        self::assertSame('whsec_existing-secret', $server->username);
    }

    public function testFormUsesBlankPasswordFieldInsteadOfRenderingStoredSecret(): void
    {
        $server = new DeliveryServerSendmuxWebApi();
        $server->username = 'whsec_existing-secret';
        $fields = $server->getFormFieldsDefinition();

        self::assertArrayHasKey('webhook_secret', $fields);
        self::assertArrayNotHasKey('username', $fields);
        self::assertStringContainsString('type="password"', $fields['webhook_secret']['fieldHtml']);
        self::assertStringNotContainsString($server->username, $fields['webhook_secret']['fieldHtml']);
    }

    public function testBlankWebhookSecretDoesNotDeactivateAnOtherwiseUnchangedServer(): void
    {
        $stored = new DeliveryServer();
        $stored->server_id = 42;
        $stored->username = 'encrypted-webhook-secret';
        DeliveryServer::$found = $stored;

        $server = new DeliveryServerSendmuxWebApi();
        $server->server_id = 42;
        $server->isNewRecord = false;
        $server->status = DeliveryServer::STATUS_ACTIVE;
        $server->username = 'whsec_existing-secret';
        $server->webhook_secret = '';

        $this->invokeValidationLifecycle($server);

        self::assertSame(DeliveryServer::STATUS_ACTIVE, $server->status);
        self::assertSame('whsec_existing-secret', $server->username);
    }

    public function testReplacingWebhookSecretStillDeactivatesServerForRetesting(): void
    {
        $stored = new DeliveryServer();
        $stored->server_id = 42;
        $stored->username = 'encrypted-webhook-secret';
        DeliveryServer::$found = $stored;

        $server = new DeliveryServerSendmuxWebApi();
        $server->server_id = 42;
        $server->isNewRecord = false;
        $server->status = DeliveryServer::STATUS_ACTIVE;
        $server->username = 'whsec_existing-secret';
        $server->webhook_secret = 'whsec_replacement-secret';

        $this->invokeValidationLifecycle($server);

        self::assertSame(DeliveryServer::STATUS_INACTIVE, $server->status);
        self::assertSame('whsec_replacement-secret', $server->username);
    }

    private function invokeValidationLifecycle(DeliveryServerSendmuxWebApi $server): void
    {
        foreach (['beforeValidate', 'afterValidate'] as $methodName) {
            $method = new ReflectionMethod($server, $methodName);
            $method->setAccessible(true);
            $method->invoke($server);
        }
    }
}
