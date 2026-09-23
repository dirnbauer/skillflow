<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Runner;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Webconsulting\Skillflow\Configuration\ExtensionSettings;
use Webconsulting\Skillflow\Domain\SkillRunContext;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Picks the execution engine for one skill run. Context runners register
 * themselves via the DI tag "skillflow.context_runner" (auto-applied to every
 * ContextAwareSkillRunnerInterface implementation, see Configuration/Services.php).
 *
 * Precedence: per-run request > skill metadata "engine" key > extension setting
 * defaultEngine > classic. 'classic' always forces the built-in chain.
 */
final readonly class EngineResolver
{
    public const string CLASSIC = ExtensionSettings::CLASSIC_ENGINE;

    /**
     * @param iterable<ContextAwareSkillRunnerInterface> $contextRunners
     */
    public function __construct(
        #[AutowireIterator('skillflow.context_runner')]
        private iterable $contextRunners,
        private ExtensionSettings $settings,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array<string, ContextAwareSkillRunnerInterface> identifier => runner
     */
    public function getRegisteredEngines(): array
    {
        $engines = [];
        foreach ($this->contextRunners as $runner) {
            $engines[$runner->getIdentifier()] = $runner;
        }
        return $engines;
    }

    /**
     * @param array<string, mixed> $skill normalized tx_nrllm_skill row
     */
    public function resolve(array $skill, SkillRunContext $context): EngineResolution
    {
        $requested = trim($context->requestedEngine);
        if ($requested === '') {
            $requested = $this->engineFromMetadata($skill) ?: $this->settings->defaultEngine;
        }
        if ($requested === '' || $requested === self::CLASSIC) {
            return new EngineResolution(null, self::CLASSIC);
        }

        $runner = $this->getRegisteredEngines()[$requested] ?? null;
        if ($runner === null || !$runner->canRun($skill, $context)) {
            if ($this->settings->engineFallback) {
                $this->logger->warning('Skill engine unavailable, falling back to the classic chain', [
                    'engine' => $requested,
                    'skill' => Typed::string($skill['identifier'] ?? ''),
                ]);
                return new EngineResolution(null, $requested);
            }
            return new EngineResolution(
                null,
                $requested,
                sprintf('Engine "%s" is unavailable and engine fallback is disabled', $requested)
            );
        }

        return new EngineResolution($runner, $requested);
    }

    /**
     * @param array<string, mixed> $skill
     */
    private function engineFromMetadata(array $skill): string
    {
        $metadata = json_decode(Typed::string($skill['metadata'] ?? ''), true);
        if (!is_array($metadata)) {
            return '';
        }
        return trim(Typed::string($metadata['engine'] ?? ''));
    }
}
