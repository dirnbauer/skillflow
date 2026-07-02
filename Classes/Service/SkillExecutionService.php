<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\Skillflow\Domain\SkillRunContext;
use Webconsulting\Skillflow\Domain\SkillRunResult;
use Webconsulting\Skillflow\Event\AfterSkillRunEvent;
use Webconsulting\Skillflow\Event\BeforeSkillRunEvent;
use Webconsulting\Skillflow\Exception\ExecutionBlockedException;
use Webconsulting\Skillflow\Runner\EngineResolver;
use Webconsulting\Skillflow\Runner\RunnerFactory;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Orchestrates a single skill run: environment guard, engine resolution
 * (classic chain or a registered context-aware engine), content collection,
 * execution and the run protocol (tx_skillflow_run). The run row is created
 * BEFORE the engine executes (two-phase persistence) so engines can cross-link
 * against a stable run uid and settle asynchronously. Never lets an AI failure
 * escape into the calling editing process.
 */
final class SkillExecutionService
{
    /** Runs stuck in the transient 'running' state longer than this are failed lazily. */
    private const STALE_RUN_SECONDS = 1800;

    public function __construct(
        private readonly EnvironmentGuard $environmentGuard,
        private readonly ContentCollector $contentCollector,
        private readonly RunnerFactory $runnerFactory,
        private readonly EngineResolver $engineResolver,
        private readonly SkillFinder $skillFinder,
        private readonly ContextResolver $contextResolver,
        private readonly ConnectionPool $connectionPool,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function runSkillOnRecord(int $skillUid, string $table, int $recordUid, int $workspaceId, int $stageUid = 0, string $instructions = '', string $engine = ''): SkillRunResult
    {
        $skill = $this->skillFinder->findSkillByUid($skillUid);
        if ($skill === null) {
            return new SkillRunResult('failed', 'Skill ' . $skillUid . ' not found', 'none');
        }

        // Phase 1: the run row exists before anything executes, so engines get
        // a stable uid to cross-link and asynchronous settlement has a target.
        $runUid = $this->insertRun($skillUid, $table, $recordUid, $workspaceId, $stageUid, $instructions);

        $resolvedInstructions = $instructions;
        $engineRequested = trim($engine);
        $context = $this->buildContext($table, $recordUid, $workspaceId, $stageUid, $resolvedInstructions, $engineRequested, $runUid);
        try {
            // Resolve {uid}/{table}/{title}/{pid}/{workspace} in the skill body and the
            // per-run instructions before anything reaches the LLM. Closed whitelist only.
            $tokens = $this->contextResolver->resolveTokens($table, $recordUid, $workspaceId);
            $skill['body'] = $this->contextResolver->apply(Typed::string($skill['body'] ?? ''), $tokens);
            $resolvedInstructions = $this->contextResolver->apply($instructions, $tokens);
            $context = $this->buildContext($table, $recordUid, $workspaceId, $stageUid, $resolvedInstructions, $engineRequested, $runUid);

            $beforeEvent = new BeforeSkillRunEvent($skill, $context, $engineRequested);
            $this->eventDispatcher->dispatch($beforeEvent);
            if ($beforeEvent->isPrevented()) {
                throw new ExecutionBlockedException('Run prevented by listener: ' . $beforeEvent->getPreventReason());
            }
            if ($beforeEvent->getEngine() !== $engineRequested || $beforeEvent->getInstructions() !== $resolvedInstructions) {
                $engineRequested = $beforeEvent->getEngine();
                $resolvedInstructions = $beforeEvent->getInstructions();
                $context = $this->buildContext($table, $recordUid, $workspaceId, $stageUid, $resolvedInstructions, $engineRequested, $runUid);
            }

            $this->environmentGuard->assertExecutionAllowed();

            $resolution = $this->engineResolver->resolve($skill, $context);
            $engineRequested = $resolution->engineRequested;
            if ($resolution->blockReason !== '') {
                throw new ExecutionBlockedException($resolution->blockReason);
            }

            $files = $this->skillFinder->findFilesForSkill($skillUid);
            if ($resolution->contextRunner !== null) {
                $content = $resolution->contextRunner->wantsCollectedContent()
                    ? $this->collectContent($table, $recordUid, $workspaceId, $resolvedInstructions)
                    : '';
                $result = $resolution->contextRunner->runInContext($skill, $context, $content, $files);
            } else {
                $runner = $this->runnerFactory->create();
                $content = $this->collectContent($table, $recordUid, $workspaceId, $resolvedInstructions);
                $result = $runner->run($skill, $content, $files);
            }
        } catch (ExecutionBlockedException $e) {
            $result = new SkillRunResult('blocked', $e->getMessage(), 'none');
            $this->logger->warning('Skill run blocked', ['skill' => $skillUid, 'reason' => $e->getMessage()]);
        } catch (\Throwable $e) {
            $result = new SkillRunResult('failed', $e->getMessage(), 'none');
            $this->logger->error('Skill run failed', ['skill' => $skillUid, 'exception' => $e]);
        }

        // Phase 2: settle the row (a 'pending' result keeps it open — the
        // engine's extension mirrors the final outcome in later).
        $this->settleRun($runUid, $result, $resolvedInstructions);
        $this->eventDispatcher->dispatch(new AfterSkillRunEvent(
            $skill,
            $context,
            $result,
            $runUid,
            $engineRequested === '' ? EngineResolver::CLASSIC : $engineRequested,
            $result->runner
        ));
        return $result;
    }

    /**
     * Lazily fail runs stuck in the transient 'running' state (a PHP fatal
     * between the two-phase insert and settle). Called when the module lists
     * runs; cheap no-op otherwise.
     *
     * 'pending' is deliberately NOT swept: it is an async state an engine set
     * on purpose ("continues on the sidecar"), settled later by the engine's
     * mirror (RunStore::markSettled) or a reconcile pass — force-failing it on
     * a timer would drop a legitimately slow run's real outcome.
     */
    public function failStaleRuns(int $maxAgeSeconds = self::STALE_RUN_SECONDS): int
    {
        $threshold = time() - $maxAgeSeconds;
        $connection = $this->connectionPool->getConnectionForTable('tx_skillflow_run');
        $queryBuilder = $connection->createQueryBuilder();
        return (int)$queryBuilder->update('tx_skillflow_run')
            ->set('status', 'failed')
            ->set('output', 'Run did not settle within ' . $maxAgeSeconds . ' seconds and was marked failed.')
            ->set('tstamp', time())
            ->where(
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter('running')),
                $queryBuilder->expr()->lt('tstamp', $queryBuilder->createNamedParameter($threshold, \Doctrine\DBAL\ParameterType::INTEGER))
            )
            ->executeStatement();
    }

    private function buildContext(string $table, int $recordUid, int $workspaceId, int $stageUid, string $instructions, string $requestedEngine, int $runUid): SkillRunContext
    {
        return new SkillRunContext(
            $table,
            $recordUid,
            $workspaceId,
            $stageUid,
            $instructions,
            $this->currentBackendUserUid(),
            $requestedEngine,
            $runUid
        );
    }

    private function collectContent(string $table, int $recordUid, int $workspaceId, string $resolvedInstructions): string
    {
        $content = $this->contentCollector->collect($table, $recordUid, $workspaceId);
        if (trim($resolvedInstructions) !== '') {
            $content = "## Per-run instructions\n\n" . $resolvedInstructions . "\n\n---\n\n" . $content;
        }
        return $content;
    }

    private function currentBackendUserUid(): int
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser instanceof BackendUserAuthentication) {
            return Typed::int($backendUser->user['uid'] ?? 0);
        }
        return 0;
    }

    private function insertRun(int $skillUid, string $table, int $recordUid, int $workspaceId, int $stageUid, string $instructions): int
    {
        $now = time();
        $connection = $this->connectionPool->getConnectionForTable('tx_skillflow_run');
        $connection->insert('tx_skillflow_run', [
            'pid' => 0,
            'crdate' => $now,
            'tstamp' => $now,
            'skill' => $skillUid,
            'target_table' => $table,
            'target_uid' => $recordUid,
            'workspace_uid' => $workspaceId,
            'stage_uid' => $stageUid,
            'status' => 'running',
            'runner' => '',
            'instructions' => mb_substr($instructions, 0, 65535),
            'output' => '',
        ]);
        return (int)$connection->lastInsertId();
    }

    private function settleRun(int $runUid, SkillRunResult $result, string $instructions): void
    {
        $this->connectionPool->getConnectionForTable('tx_skillflow_run')->update('tx_skillflow_run', [
            'tstamp' => time(),
            'status' => $result->status,
            'runner' => $result->runner,
            'instructions' => mb_substr($instructions, 0, 65535),
            'output' => mb_substr($result->output, 0, 16000000),
            'verdict' => mb_substr($result->verdict, 0, 32),
            'score' => $result->score,
            'result_json' => mb_substr($result->resultJson, 0, 16000000),
            'external_engine' => mb_substr($result->externalEngine, 0, 32),
            'external_ref' => mb_substr($result->externalRef, 0, 190),
            'external_url' => $result->externalUrl,
        ], ['uid' => $runUid]);
    }
}
