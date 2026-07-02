<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Domain\Security;

/**
 * The full review report for one skill: security findings + the license
 * assessment + whether the skill ships code examples. Persisted as JSON on
 * tx_skillflow_skill.check_report and rendered in the Skills module so a
 * reviewer sees, per skill, exactly what to check. Purely advisory.
 */
final readonly class SkillCheckReport
{
    /** Severity ordering for "highest wins". */
    private const RANK = ['none' => 0, 'info' => 1, 'warning' => 2, 'danger' => 3];

    /**
     * @param list<SkillCheckFinding> $findings
     */
    public function __construct(
        public array $findings,
        public LicenseAssessment $license,
        /** True when the skill ships code (supporting code files or fenced code in the body). */
        public bool $hasCode,
        public int $generatedAt,
    ) {
    }

    /**
     * Highest severity across findings + the license warning, for the list badge.
     * License warnings never exceed 'warning' (they must not read as a hard block),
     * and only count when the skill ships CODE — an unknown/odd license on an
     * instruction-only skill has nothing to reuse, so it must not flag every row.
     */
    public function level(): string
    {
        $level = 'none';
        foreach ($this->findings as $finding) {
            if ((self::RANK[$finding->severity] ?? 0) > (self::RANK[$level] ?? 0)) {
                $level = $finding->severity;
            }
        }
        if ($this->hasCode && $this->license->isWarning() && (self::RANK['warning'] > (self::RANK[$level] ?? 0))) {
            $level = 'warning';
        }
        return $level;
    }

    public function findingCount(): int
    {
        return count($this->findings);
    }

    /**
     * @return array{generatedAt: int, hasCode: bool, level: string, license: array<string, string>, findings: list<array<string, string>>}
     */
    public function toArray(): array
    {
        return [
            'generatedAt' => $this->generatedAt,
            'hasCode' => $this->hasCode,
            'level' => $this->level(),
            'license' => $this->license->toArray(),
            'findings' => array_map(static fn (SkillCheckFinding $f): array => $f->toArray(), $this->findings),
        ];
    }
}
