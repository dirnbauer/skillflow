<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Webconsulting\Skillflow\Configuration\ExtensionSettings;
use Webconsulting\Skillflow\Configuration\RunnerMode;

final class ExtensionSettingsTest extends TestCase
{
    public function testEmptyConfigurationYieldsTheDocumentedDefaults(): void
    {
        $settings = ExtensionSettings::fromArray([]);

        self::assertSame(RunnerMode::Api, $settings->runner);
        self::assertSame('claude-sonnet-5', $settings->model);
        self::assertSame('ANTHROPIC_API_KEY', $settings->apiKeyEnvVar);
        self::assertSame(2048, $settings->maxTokens);
        self::assertSame('claude', $settings->claudeBinary);
        self::assertSame('', $settings->mcpServersJson);
        self::assertSame('', $settings->mcpConfigJson);
        self::assertSame('classic', $settings->defaultEngine);
        self::assertTrue($settings->engineFallback);
        self::assertTrue($settings->requireLocalEnvironment);
        self::assertSame('typo3', $settings->abilitiesMcpServer);
    }

    /**
     * A model stored before 1.8.0 stays: only an unset model falls back to
     * the new default.
     */
    public function testAConfiguredModelIsKept(): void
    {
        self::assertSame('claude-sonnet-4-6', ExtensionSettings::fromArray(['model' => 'claude-sonnet-4-6'])->model);
        self::assertSame('claude-opus-5-5', ExtensionSettings::fromArray(['model' => 'claude-opus-5-5'])->model);
        self::assertSame('claude-sonnet-5', ExtensionSettings::fromArray(['model' => '  '])->model);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function abilitiesMcpServerValues(): iterable
    {
        yield 'unset' => [null, 'typo3'];
        yield 'custom name' => [' abilities_server ', 'abilities_server'];
        yield 'dashes and digits' => ['typo3-lab-2', 'typo3-lab-2'];
        yield 'the tool rule separator' => ['typo3__x', 'typo3'];
        yield 'space' => ['my server', 'typo3'];
        yield 'dot' => ['typo3.local', 'typo3'];
    }

    #[DataProvider('abilitiesMcpServerValues')]
    public function testAbilitiesMcpServerIsAValidServerName(mixed $value, string $expected): void
    {
        self::assertSame($expected, ExtensionSettings::fromArray(['abilitiesMcpServer' => $value])->abilitiesMcpServer);
    }

    public function testStoredStringsAreTrimmedAndTyped(): void
    {
        $settings = ExtensionSettings::fromArray([
            'runner' => ' cli ',
            'model' => ' claude-sonnet-5 ',
            'apiKeyEnvVar' => 'MY_KEY',
            'maxTokens' => '4096',
            'claudeBinary' => '/usr/local/bin/claude',
            'mcpServersJson' => ' [] ',
            'defaultEngine' => 'review',
            'engineFallback' => '0',
            'requireLocalEnvironment' => '0',
        ]);

        self::assertSame(RunnerMode::Cli, $settings->runner);
        self::assertSame('claude-sonnet-5', $settings->model);
        self::assertSame('MY_KEY', $settings->apiKeyEnvVar);
        self::assertSame(4096, $settings->maxTokens);
        self::assertSame('/usr/local/bin/claude', $settings->claudeBinary);
        self::assertSame('[]', $settings->mcpServersJson);
        self::assertSame('review', $settings->defaultEngine);
        self::assertFalse($settings->engineFallback);
        self::assertFalse($settings->requireLocalEnvironment);
    }

    /** @return iterable<string, array{mixed, RunnerMode}> */
    public static function runnerValues(): iterable
    {
        yield 'api' => ['api', RunnerMode::Api];
        yield 'anthropic' => ['anthropic', RunnerMode::Anthropic];
        yield 'cli' => ['cli', RunnerMode::Cli];
        yield 'unknown value' => ['openai', RunnerMode::Api];
        yield 'not a string' => [['cli'], RunnerMode::Api];
    }

    #[DataProvider('runnerValues')]
    public function testUnknownRunnerFallsBackToApi(mixed $value, RunnerMode $expected): void
    {
        self::assertSame($expected, ExtensionSettings::fromArray(['runner' => $value])->runner);
    }

    public function testMaxTokensHasAFloor(): void
    {
        self::assertSame(256, ExtensionSettings::fromArray(['maxTokens' => '10'])->maxTokens);
    }

    public function testEmptyFlagsKeepTheSafeDefault(): void
    {
        $settings = ExtensionSettings::fromArray(['engineFallback' => '', 'requireLocalEnvironment' => '']);

        self::assertTrue($settings->engineFallback);
        self::assertTrue($settings->requireLocalEnvironment);
    }

    public function testUnreadableConfigurationFallsBackToDefaults(): void
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willThrowException(new \RuntimeException('Not configured'));

        self::assertEquals(new ExtensionSettings(), ExtensionSettings::load($configuration));
    }
}
