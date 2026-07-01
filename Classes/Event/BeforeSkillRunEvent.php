<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Event;

use Webconsulting\Skillflow\Domain\SkillRunContext;

/**
 * Dispatched after the pending tx_skillflow_run row exists and before the
 * engine executes. Listeners may reroute the run to another engine, adjust
 * the (already token-resolved) instructions, or prevent the run entirely.
 */
final class BeforeSkillRunEvent
{
    private string $instructions;
    private bool $prevented = false;
    private string $preventReason = '';

    /**
     * @param array<string, mixed> $skill tx_skillflow_skill row, body token-resolved
     */
    public function __construct(
        private readonly array $skill,
        private readonly SkillRunContext $context,
        private string $engine,
    ) {
        $this->instructions = $context->instructions;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSkill(): array
    {
        return $this->skill;
    }

    public function getContext(): SkillRunContext
    {
        return $this->context;
    }

    public function getEngine(): string
    {
        return $this->engine;
    }

    public function setEngine(string $engine): void
    {
        $this->engine = $engine;
    }

    public function getInstructions(): string
    {
        return $this->instructions;
    }

    public function setInstructions(string $instructions): void
    {
        $this->instructions = $instructions;
    }

    public function preventRun(string $reason): void
    {
        $this->prevented = true;
        $this->preventReason = $reason;
    }

    public function isPrevented(): bool
    {
        return $this->prevented;
    }

    public function getPreventReason(): string
    {
        return $this->preventReason;
    }
}
