<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Unit\Runner;

use Netresearch\NrLlm\Domain\Model\CompletionResponse;
use Netresearch\NrLlm\Domain\Model\LlmConfiguration;
use Netresearch\NrLlm\Domain\Model\UsageStatistics;
use Netresearch\NrLlm\Exception\OutputBudgetExhaustedException;
use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use PHPUnit\Framework\TestCase;
use Webconsulting\Skillflow\Configuration\ExtensionSettings;
use Webconsulting\Skillflow\Exception\ExecutionBlockedException;
use Webconsulting\Skillflow\Runner\NrLlmRunner;
use Webconsulting\Skillflow\Runner\PromptBuilder;

final class NrLlmRunnerTest extends TestCase
{
    public function testMissingUsableDefaultBlocksBeforeChat(): void
    {
        $manager = $this->createMock(LlmServiceManagerInterface::class);
        $manager->expects($this->once())->method('resolveEffectiveConfiguration')->willReturn(null);
        $manager->expects($this->never())->method('chat');
        $runner = new NrLlmRunner(new ExtensionSettings(), new PromptBuilder(), $manager);

        $this->expectException(ExecutionBlockedException::class);
        $runner->run(['name' => 'Review'], 'Private page content');
    }

    public function testConfigurationFailureIsUnavailable(): void
    {
        $manager = $this->createMock(LlmServiceManagerInterface::class);
        $manager->expects($this->once())->method('resolveEffectiveConfiguration')->willThrowException(new \RuntimeException('Unavailable'));
        $manager->expects($this->never())->method('chat');
        $runner = new NrLlmRunner(new ExtensionSettings(), new PromptBuilder(), $manager);

        self::assertFalse($runner->isAvailable());
    }

    public function testExhaustedOutputBudgetFailsWithTheLimitsToRaise(): void
    {
        $manager = $this->createMock(LlmServiceManagerInterface::class);
        $manager->method('resolveEffectiveConfiguration')->willReturn(new LlmConfiguration());
        $exhausted = new OutputBudgetExhaustedException('default-chat', 1024);
        $manager->expects($this->once())->method('chat')->willThrowException($exhausted);
        $runner = new NrLlmRunner(ExtensionSettings::fromArray(['maxTokens' => '1024']), new PromptBuilder(), $manager);

        try {
            $runner->run(['name' => 'Review'], 'Page content');
            self::fail('An exhausted output budget must fail the run.');
        } catch (\RuntimeException $e) {
            self::assertSame(1760002003, $e->getCode());
            self::assertSame($exhausted, $e->getPrevious());
            self::assertStringContainsString('"maxTokens" (now 1024)', $e->getMessage());
            self::assertStringContainsString('"default-chat"', $e->getMessage());
        }
    }

    public function testEmptyAnswerStillFailsTheRun(): void
    {
        $manager = self::createStub(LlmServiceManagerInterface::class);
        $manager->method('resolveEffectiveConfiguration')->willReturn(new LlmConfiguration());
        $manager->method('chat')->willReturn(new CompletionResponse('  ', 'model', new UsageStatistics(1, 0, 1), 'length'));
        $runner = new NrLlmRunner(new ExtensionSettings(), new PromptBuilder(), $manager);

        $this->expectExceptionCode(1760002002);
        $runner->run(['name' => 'Review'], 'Page content');
    }
}
