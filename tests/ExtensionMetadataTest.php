<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/framework-stubs.php';
require_once dirname(__DIR__) . '/SendmuxWebApiExt.php';

final class ExtensionMetadataTest extends TestCase
{
    public function testVersionAndMinimumMailWizzReleaseMatchCorrelatedWebhookContract(): void
    {
        $extension = new SendmuxWebApiExt();

        self::assertSame('0.3.0', $extension->version);
        self::assertSame('2.0.34', $extension->minAppVersion);
    }
}
