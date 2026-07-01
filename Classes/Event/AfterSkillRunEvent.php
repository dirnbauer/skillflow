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
final class AfterSkillRunEvent
{
    /**
     * @param array<string, mixed> $skill tx_skillflow_skill row
     */
    public function __construct(
        public readonly array $skill,
        public readonly SkillRunContext $context,
        public readonly SkillRunResult $result,
        public readonly int $runUid,
        public readonly string $engineRequested,
        public readonly string $engineUsed,
    ) {
    }
}
