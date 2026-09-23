<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Domain;

final readonly class SkillRunResult
{
    public function __construct(
        /** A RunStatus value: success | failed | blocked | pending (engine continues asynchronously) */
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
        /** Engine-side run reference, e.g. 'tx_myengine_run:123' */
        public string $externalRef = '',
        /** Backend deep link to the engine's run view */
        public string $externalUrl = '',
        /** The tx_skillflow_run row this result settled; 0 when no row was written. Set by SkillExecutionService. */
        public int $runUid = 0,
    ) {}

    public static function blocked(string $reason): self
    {
        return new self(RunStatus::Blocked->value, $reason, 'none');
    }

    public static function failed(string $reason): self
    {
        return new self(RunStatus::Failed->value, $reason, 'none');
    }

    public function runStatus(): RunStatus
    {
        return RunStatus::fromValue($this->status);
    }

    public function isSuccess(): bool
    {
        return $this->runStatus() === RunStatus::Success;
    }

    public function withRunUid(int $runUid): self
    {
        return new self(
            $this->status,
            $this->output,
            $this->runner,
            $this->verdict,
            $this->score,
            $this->resultJson,
            $this->externalEngine,
            $this->externalRef,
            $this->externalUrl,
            $runUid,
        );
    }
}
