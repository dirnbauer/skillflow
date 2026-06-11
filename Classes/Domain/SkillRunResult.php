<?php

declare(strict_types=1);

namespace Webconsulting\Skills\Domain;

final readonly class SkillRunResult
{
    public function __construct(
        public string $status,
        public string $output,
        public string $runner,
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }
}
