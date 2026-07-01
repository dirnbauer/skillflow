<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Domain;

/**
 * Target-record context handed to context-aware execution engines
 * (ContextAwareSkillRunnerInterface). Engines that read the record live
 * (e.g. through MCP tools) work from this instead of pre-collected content.
 */
final readonly class SkillRunContext
{
    public function __construct(
        public string $table,
        public int $recordUid,
        public int $workspaceId,
        public int $stageUid,
        /** Per-run instructions, {uid}/{table}/{pid}/{title}/{workspace} tokens already resolved */
        public string $instructions,
        /** The TRIGGERING backend user — audit only; engines must use their own identity */
        public int $backendUserUid,
        /** Engine requested for this run ('' = auto, 'classic' forces the classic chain) */
        public string $requestedEngine,
        /** Pre-created tx_skillflow_run uid (two-phase persistence) for cross-linking */
        public int $skillRunUid,
    ) {
    }
}
