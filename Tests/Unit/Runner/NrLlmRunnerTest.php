<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Unit\Runner;

use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use Webconsulting\Skillflow\Exception\ExecutionBlockedException;
use Webconsulting\Skillflow\Runner\NrLlmRunner;
use Webconsulting\Skillflow\Runner\PromptBuilder;

final class NrLlmRunnerTest extends TestCase
{
    public function testMissingUsableDefaultBlocksBeforeChat(): void
    {
        $manager = $this->createMock(LlmServiceManagerInterface::class);
        $manager->expects(self::once())->method('resolveEffectiveConfiguration')->willReturn(null);
        $manager->expects(self::never())->method('chat');
        $runner = new NrLlmRunner(new ExtensionConfiguration(), new PromptBuilder(), $manager);

        $this->expectException(ExecutionBlockedException::class);
        $runner->run(['name' => 'Review'], 'Private page content');
    }

    public function testConfigurationFailureIsUnavailable(): void
    {
        $manager = $this->createMock(LlmServiceManagerInterface::class);
        $manager->expects(self::once())->method('resolveEffectiveConfiguration')->willThrowException(new \RuntimeException('Unavailable'));
        $manager->expects(self::never())->method('chat');
        $runner = new NrLlmRunner(new ExtensionConfiguration(), new PromptBuilder(), $manager);

        self::assertFalse($runner->isAvailable());
    }
}
