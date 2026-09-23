<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Unit\Runner;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Webconsulting\Skillflow\Configuration\ExtensionSettings;
use Webconsulting\Skillflow\Domain\SkillRunContext;
use Webconsulting\Skillflow\Runner\ContextAwareSkillRunnerInterface;
use Webconsulting\Skillflow\Runner\EngineResolver;

final class EngineResolverTest extends TestCase
{
    #[DataProvider('unavailableEngines')]
    public function testUnavailableEngineRespectsFallback(bool $registered, bool $fallback): void
    {
        $runner = self::createStub(ContextAwareSkillRunnerInterface::class);
        $runner->method('getIdentifier')->willReturn('review');
        $runner->method('canRun')->willReturn(false);

        $resolver = new EngineResolver($registered ? [$runner] : [], ExtensionSettings::fromArray(['engineFallback' => $fallback ? '1' : '0']), new NullLogger());
        $result = $resolver->resolve([], new SkillRunContext('pages', 1, 0, 0, '', 0, 'review', 1));

        self::assertNull($result->contextRunner);
        self::assertSame('review', $result->engineRequested);
        self::assertSame($fallback, $result->blockReason === '');
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function unavailableEngines(): iterable
    {
        yield 'unregistered, fallback disabled' => [false, false];
        yield 'unregistered, fallback enabled' => [false, true];
        yield 'registered, fallback disabled' => [true, false];
        yield 'registered, fallback enabled' => [true, true];
    }

    public function testExplicitClassicOverridesSkillAndDefaultEngine(): void
    {
        $resolver = new EngineResolver([], new ExtensionSettings(defaultEngine: 'review'), new NullLogger());

        $result = $resolver->resolve(
            ['metadata' => '{"engine":"other"}'],
            new SkillRunContext('pages', 1, 0, 0, '', 0, 'classic', 1),
        );

        self::assertSame('classic', $result->engineRequested);
        self::assertSame('', $result->blockReason);
        self::assertNull($result->contextRunner);
    }
}
