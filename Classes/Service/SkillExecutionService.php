<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\Skillflow\Domain\SkillRunResult;
use Webconsulting\Skillflow\Exception\ExecutionBlockedException;
use Webconsulting\Skillflow\Runner\RunnerFactory;

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
        private readonly ConnectionPool $connectionPool,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function runSkillOnRecord(int $skillUid, string $table, int $recordUid, int $workspaceId, int $stageUid = 0): SkillRunResult
    {
        $skill = $this->skillFinder->findSkillByUid($skillUid);
        if ($skill === null) {
            return new SkillRunResult('failed', 'Skill ' . $skillUid . ' not found', 'none');
        }

        try {
            $this->environmentGuard->assertExecutionAllowed();
            $runner = $this->runnerFactory->create();
            $content = $this->contentCollector->collect($table, $recordUid, $workspaceId);
            $files = $this->skillFinder->findFilesForSkill($skillUid);
            $result = $runner->run($skill, $content, $files);
        } catch (ExecutionBlockedException $e) {
            $result = new SkillRunResult('blocked', $e->getMessage(), 'none');
            $this->logger->warning('Skill run blocked', ['skill' => $skillUid, 'reason' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $result = new SkillRunResult('failed', $e->getMessage(), 'none');
            $this->logger->error('Skill run failed', ['skill' => $skillUid, 'exception' => $e]);
        }

        $this->persistRun($skillUid, $table, $recordUid, $workspaceId, $stageUid, $result);
        return $result;
    }

    private function persistRun(int $skillUid, string $table, int $recordUid, int $workspaceId, int $stageUid, SkillRunResult $result): void
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
            'output' => mb_substr($result->output, 0, 16000000),
        ]);
    }
}
