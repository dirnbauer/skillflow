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
     * @param array<int, array<string, mixed>> $files tx_skillflow_file rows (relative_path, content)
     */
    public function run(array $skill, string $content, array $files = []): SkillRunResult;

    public function getName(): string;
}
