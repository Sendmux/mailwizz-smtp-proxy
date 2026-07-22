<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SendmuxWebhookSignatureVerifierTest extends TestCase
{
    private string $verifierPath;

    protected function setUp(): void
    {
        $this->verifierPath = dirname(__DIR__) . '/common/components/SendmuxWebhookSignatureVerifier.php';

        self::assertFileExists($this->verifierPath);
        require_once $this->verifierPath;
    }

    public function testAcceptsExactBodySignedWithWebhookSecret(): void
    {
        $body = '{"id":"evt_test","type":"message.bounced"}';
        $secret = 'whsec_test-secret';
        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

        self::assertTrue(SendmuxWebhookSignatureVerifier::verify($body, $signature, $secret));
    }

    public function testRejectsSignatureWhenBodyChangesByOneByte(): void
    {
        $body = '{"id":"evt_test","type":"message.bounced"}';
        $secret = 'whsec_test-secret';
        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

        self::assertFalse(SendmuxWebhookSignatureVerifier::verify($body . ' ', $signature, $secret));
    }

    /**
     * @dataProvider invalidSignatureProvider
     */
    public function testRejectsMissingMalformedAndWrongSignatures(string $signature, string $secret): void
    {
        self::assertFalse(SendmuxWebhookSignatureVerifier::verify('{"id":"evt_test"}', $signature, $secret));
    }

    public function invalidSignatureProvider(): array
    {
        return [
            'missing signature' => ['', 'whsec_test-secret'],
            'malformed signature' => ['not-a-signature', 'whsec_test-secret'],
            'wrong secret' => [
                'sha256=' . hash_hmac('sha256', '{"id":"evt_test"}', 'whsec_different-secret'),
                'whsec_test-secret',
            ],
            'missing secret' => [str_repeat('a', 64), ''],
        ];
    }
}
