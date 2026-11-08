<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Domain\Catalog;

/**
 * A coding agent that loads skills from a folder: where the folder is and
 * how a skill is called by name in its chat.
 */
final readonly class SkillClient
{
    public function __construct(
        public string $key,
        public string $label,
        /** Skills folder of the user, e.g. "~/.claude/skills" */
        public string $directory,
        /** How a skill is called by name, "{skill}" stands for the name ("/{skill}"); '' when the agent has no such call */
        public string $call,
    ) {}

    public function callFor(string $skill): string
    {
        return $this->call === '' ? '' : str_replace('{skill}', $skill, $this->call);
    }
}
