<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Event;

use Webconsulting\Skillflow\Domain\ImportResult;

/**
 * Dispatched at the end of SkillImportService::importFromPath() — once per
 * import batch, covering folder scans AND repository syncs (which delegate to
 * importFromPath). Typical consumer: an execution-engine extension exporting
 * changed skills to its runtime incrementally (use the per-skill status and
 * contentHash; 'unchanged' skills need no re-export).
 */
final class AfterSkillsSyncedEvent
{
    /**
     * @param 'folder'|'repository'|string $sourceType
     * @param list<array{uid: int, identifier: string, status: 'created'|'updated'|'unchanged', contentHash: string}> $skills
     */
    public function __construct(
        public readonly string $sourceType,
        public readonly int $repositoryUid,
        public readonly ImportResult $result,
        public readonly array $skills,
    ) {
    }
}
