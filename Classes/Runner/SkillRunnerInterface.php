<?php

declare(strict_types=1);

namespace Webconsulting\Skills\Runner;

use Webconsulting\Skills\Domain\SkillRunResult;

interface SkillRunnerInterface
{
    /**
     * Executes a skill against collected record content and returns the report.
     *
     * @param array<string, mixed> $skill tx_webconskills_skill row
     */
    public function run(array $skill, string $content): SkillRunResult;

    public function getName(): string;
}
