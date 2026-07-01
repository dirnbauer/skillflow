<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Domain;

final readonly class SkillRunResult
{
    public function __construct(
        /** success | failed | blocked | pending (engine continues asynchronously) */
        public string $status,
        public string $output,
        public string $runner,
        /** Free-form engine verdict, e.g. READY / NEEDS_WORK / BLOCKER */
        public string $verdict = '',
        /** 0-100 quality score; -1 = no score */
        public int $score = -1,
        /** Structured engine result as JSON */
        public string $resultJson = '',
        public string $externalEngine = '',
        /** Engine-side run reference, e.g. 'tx_flue_run:123' */
        public string $externalRef = '',
        /** Backend deep link to the engine's run view */
        public string $externalUrl = '',
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }
}
