<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Unit\Runner;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Webconsulting\Skillflow\Domain\SkillRunContext;
use Webconsulting\Skillflow\Runner\ContextAwareSkillRunnerInterface;
use Webconsulting\Skillflow\Runner\EngineResolver;

final class EngineResolverTest extends TestCase
{
    #[DataProvider('unavailableEngines')]
    public function testUnavailableEngineRespectsFallback(bool $registered, bool $fallback): void
    {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['engineFallback' => $fallback ? '1' : '0']);
        $runner = $this->createStub(ContextAwareSkillRunnerInterface::class);
        $runner->method('getIdentifier')->willReturn('review');
        $runner->method('canRun')->willReturn(false);

        $resolver = new EngineResolver($registered ? [$runner] : [], $configuration, new NullLogger());
        $result = $resolver->resolve([], new SkillRunContext('pages', 1, 0, 0, '', 0, 'review', 1));

        self::assertNull($result->contextRunner);
        self::assertSame('review', $result->engineRequested);
        self::assertSame($fallback, $result->blockReason === '');
    }

    public static function unavailableEngines(): iterable
    {
        yield 'unregistered, fallback disabled' => [false, false];
        yield 'unregistered, fallback enabled' => [false, true];
        yield 'registered, fallback disabled' => [true, false];
        yield 'registered, fallback enabled' => [true, true];
    }

    public function testExplicitClassicOverridesSkillAndDefaultEngine(): void
    {
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn(['defaultEngine' => 'review']);
        $resolver = new EngineResolver([], $configuration, new NullLogger());

        $result = $resolver->resolve(
            ['metadata' => '{"engine":"other"}'],
            new SkillRunContext('pages', 1, 0, 0, '', 0, 'classic', 1),
        );

        self::assertSame('classic', $result->engineRequested);
        self::assertSame('', $result->blockReason);
        self::assertNull($result->contextRunner);
    }
}
