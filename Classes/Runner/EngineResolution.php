<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Runner;

/**
 * Outcome of EngineResolver::resolve(): which engine handles the run.
 * A null $contextRunner means the classic chain (RunnerFactory).
 * A non-empty $blockReason means the run must not execute at all
 * (requested engine unavailable and fallback disabled).
 */
final readonly class EngineResolution
{
    public function __construct(
        public ?ContextAwareSkillRunnerInterface $contextRunner,
        public string $engineRequested,
        public string $blockReason = '',
    ) {
    }
}
