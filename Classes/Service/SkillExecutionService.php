<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\Skillflow\Domain\SkillRunResult;
use Webconsulting\Skillflow\Exception\ExecutionBlockedException;
use Webconsulting\Skillflow\Runner\RunnerFactory;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Orchestrates a single skill run: environment guard, content collection,
 * runner execution and persisting the run protocol (tx_skillflow_run).
 * Never lets an AI failure escape into the calling editing process.
 */
final class SkillExecutionService
{
    public function __construct(
        private readonly EnvironmentGuard $environmentGuard,
        private readonly ContentCollector $contentCollector,
        private readonly RunnerFactory $runnerFactory,
        private readonly SkillFinder $skillFinder,
        private readonly ContextResolver $contextResolver,
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function runSkillOnRecord(int $skillUid, string $table, int $recordUid, int $workspaceId, int $stageUid = 0, string $instructions = ''): SkillRunResult
    {
        $skill = $this->skillFinder->findSkillByUid($skillUid);
        if ($skill === null) {
            return new SkillRunResult('failed', 'Skill ' . $skillUid . ' not found', 'none');
        }

        $resolvedInstructions = '';
        try {
            $this->environmentGuard->assertExecutionAllowed();
            $runner = $this->runnerFactory->create();

            // Resolve {uid}/{table}/{title}/{pid}/{workspace} in the skill body and the
            // per-run instructions before anything reaches the LLM. Closed whitelist only.
            $tokens = $this->contextResolver->resolveTokens($table, $recordUid, $workspaceId);
            $skill['body'] = $this->contextResolver->apply(Typed::string($skill['body'] ?? ''), $tokens);
            $resolvedInstructions = $this->contextResolver->apply($instructions, $tokens);

            $content = $this->contentCollector->collect($table, $recordUid, $workspaceId);
            if (trim($resolvedInstructions) !== '') {
                $content = "## Per-run instructions\n\n" . $resolvedInstructions . "\n\n---\n\n" . $content;
            }

            $files = $this->skillFinder->findFilesForSkill($skillUid);
            $result = $runner->run($skill, $content, $files);
        } catch (ExecutionBlockedException $e) {
            $result = new SkillRunResult('blocked', $e->getMessage(), 'none');
            $this->logger->warning('Skill run blocked', ['skill' => $skillUid, 'reason' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $result = new SkillRunResult('failed', $e->getMessage(), 'none');
            $this->logger->error('Skill run failed', ['skill' => $skillUid, 'exception' => $e]);
        }

        $this->persistRun($skillUid, $table, $recordUid, $workspaceId, $stageUid, $result, $resolvedInstructions);
        return $result;
    }

    private function persistRun(int $skillUid, string $table, int $recordUid, int $workspaceId, int $stageUid, SkillRunResult $result, string $instructions = ''): void
    {
        $now = time();
        $this->connectionPool->getConnectionForTable('tx_skillflow_run')->insert('tx_skillflow_run', [
            'pid' => 0,
            'crdate' => $now,
            'tstamp' => $now,
            'skill' => $skillUid,
            'target_table' => $table,
            'target_uid' => $recordUid,
            'workspace_uid' => $workspaceId,
            'stage_uid' => $stageUid,
            'status' => $result->status,
            'runner' => $result->runner,
            'instructions' => mb_substr($instructions, 0, 65535),
            'output' => mb_substr($result->output, 0, 16000000),
        ]);
    }
}
