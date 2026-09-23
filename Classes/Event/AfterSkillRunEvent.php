<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Event;

use Webconsulting\Skillflow\Domain\SkillRunContext;
use Webconsulting\Skillflow\Domain\SkillRunResult;

/**
 * Dispatched after the tx_skillflow_run row was updated with the outcome.
 * Read-only. $engineRequested and $engineUsed diverge when the requested
 * engine was unavailable and the run fell back to the classic chain.
 */
final readonly class AfterSkillRunEvent
{
    /**
     * @param array<string, mixed> $skill normalized tx_nrllm_skill row
     */
    public function __construct(
        public array $skill,
        public SkillRunContext $context,
        public SkillRunResult $result,
        public int $runUid,
        public string $engineRequested,
        public string $engineUsed,
    ) {}
}
