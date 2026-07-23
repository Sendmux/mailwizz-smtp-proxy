<?php

declare(strict_types=1);

if (!defined('MW_PATH')) {
    exit('No direct script access allowed');
}

/**
 * Verifies Sendmux webhook signatures against the exact request body.
 */
final class SendmuxWebhookSignatureVerifier
{
    public static function verify(string $rawBody, string $signature, string $secret): bool
    {
        if ($rawBody === '' || $secret === '') {
            return false;
        }

        if (!preg_match('/\Asha256=([a-f0-9]{64})\z/', $signature, $matches)) {
            return false;
        }

        $expectedDigest = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expectedDigest, $matches[1]);
    }
}
