<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Runner;

use Webconsulting\Skillflow\Domain\SkillRunResult;

interface SkillRunnerInterface
{
    /**
     * Executes a skill against collected record content and returns the report.
     *
     * @param array<string, mixed> $skill tx_skillflow_skill row
     */
    public function run(array $skill, string $content): SkillRunResult;

    public function getName(): string;
}
