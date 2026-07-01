<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Runner;

use Webconsulting\Skillflow\Domain\SkillRunContext;
use Webconsulting\Skillflow\Domain\SkillRunResult;

/**
 * A pluggable execution engine that receives the target-record context instead
 * of pre-collected content — e.g. an agent runtime reading the record live via
 * MCP. Implementations in ANY extension are auto-registered through the DI tag
 * "skillflow.context_runner" (see Configuration/Services.php); skillflow itself
 * never references a concrete engine.
 *
 * Deliberately does NOT extend SkillRunnerInterface: engines are not forced to
 * support the content-based classic path.
 */
interface ContextAwareSkillRunnerInterface
{
    /** Stable engine identifier (e.g. 'flue'). 'classic' is reserved for the built-in chain. */
    public function getIdentifier(): string;

    /**
     * Fast availability probe (backend reachable, environment allowed).
     * MUST NOT throw — an unavailable engine falls back or blocks, it never errors.
     *
     * @param array<string, mixed> $skill tx_skillflow_skill row
     */
    public function canRun(array $skill, SkillRunContext $context): bool;

    /**
     * Whether SkillExecutionService should run ContentCollector for this engine.
     * Engines that read the record live return false and receive $content = ''.
     */
    public function wantsCollectedContent(): bool;

    /**
     * Execute one skill in the given record context. MUST NOT throw — map every
     * failure to a SkillRunResult with status 'failed' (or 'pending' when the
     * engine continues asynchronously and settles the run row later).
     *
     * @param array<string, mixed> $skill tx_skillflow_skill row, body token-resolved
     * @param array<int, array<string, mixed>> $files tx_skillflow_file rows
     */
    public function runInContext(array $skill, SkillRunContext $context, string $content = '', array $files = []): SkillRunResult;
}
