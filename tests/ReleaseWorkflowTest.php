<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReleaseWorkflowTest extends TestCase
{
    public function testReleasePackagingDependsOnAutomatedTests(): void
    {
        $workflow = (string)file_get_contents(dirname(__DIR__) . '/.github/workflows/release.yml');

        self::assertStringContainsString('pull_request:', $workflow);
        self::assertStringContainsString('composer install', $workflow);
        self::assertStringContainsString('composer test', $workflow);
        self::assertMatchesRegularExpression('/release:\s+needs:\s+test\b/s', $workflow);
        self::assertStringContainsString("startsWith(github.ref, 'refs/tags/v')", $workflow);
        self::assertStringContainsString(
            'actions/checkout@11d5960a326750d5838078e36cf38b85af677262',
            $workflow
        );
        self::assertStringContainsString(
            'softprops/action-gh-release@3bb12739c298aeb8a4eeaf626c5b8d85266b0e65',
            $workflow
        );
    }

    public function testReleaseWorkflowPinsPhpMatrixAndSetupAction(): void
    {
        $workflow = (string)file_get_contents(dirname(__DIR__) . '/.github/workflows/release.yml');

        self::assertStringContainsString("php-version: ['8.0', '8.3']", $workflow);
        self::assertStringContainsString(
            'shivammathur/setup-php@accd6127cb78bee3e8082180cb391013d204ef9f',
            $workflow
        );
    }
}
