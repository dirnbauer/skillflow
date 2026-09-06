<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Runner;

use Webconsulting\Skillflow\Domain\SkillRunResult;

interface SkillRunnerInterface
{
    /**
     * Executes a skill against collected record content and returns the report.
     *
     * @param array<string, mixed> $skill normalized tx_nrllm_skill row
     * @param array<int, array<string, mixed>> $files Retained for compatibility; built-in runners use prose only.
     */
    public function run(array $skill, string $content, array $files = []): SkillRunResult;

    public function getName(): string;
}
