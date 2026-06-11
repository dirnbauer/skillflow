<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Hooks;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillflow\Service\SkillExecutionService;
use Webconsulting\Skillflow\Support\Typed;
use Webconsulting\Skillflow\Service\SkillFinder;

/**
 * Workspace integration:
 *
 * 1. processCmdmap_postProcess: when a record is sent to a workspace stage
 *    ("setStage"), all skills assigned to that stage (with auto-run enabled)
 *    are executed against the record and their reports stored as run records.
 *
 * 2. processDatamap_*: when a NEW element is created inside a workspace whose
 *    sys_workspace record has "auto workflow" enabled, the element is
 *    automatically sent to the configured stage - which starts the review
 *    workflow (and thereby the stage skills).
 */
final class DataHandlerHook
{
    private static bool $autoWorkflowRunning = false;

    /** @var array<int, array{table: string, uid: int, stage: int}> */
    private array $pendingAutoWorkflow = [];

    public function __construct(
        private readonly SkillFinder $skillFinder,
        private readonly SkillExecutionService $skillExecutionService,
    ) {
    }

    /**
     * @param int|string $id
     * @param mixed $value
     */
    public function processCmdmap_postProcess(string $command, string $table, $id, $value, DataHandler $dataHandler): void
    {
        if ($command !== 'version' || !is_array($value) || ($value['action'] ?? '') !== 'setStage') {
            return;
        }
        // Only custom stages (positive uids) can carry skills; 0 = editing, -10 = ready to publish
        $stageUid = Typed::int($value['stageId'] ?? 0);
        if ($stageUid <= 0) {
            return;
        }
        $skills = $this->skillFinder->findSkillsForStage($stageUid, true);
        if ($skills === []) {
            return;
        }

        $workspaceId = (int)$dataHandler->BE_USER->workspace;
        foreach ($skills as $skill) {
            $result = $this->skillExecutionService->runSkillOnRecord(
                Typed::int($skill['uid']),
                $table,
                (int)$id,
                $workspaceId,
                $stageUid
            );
            $this->notify(
                sprintf('Skill "%s" (%s) for %s:%d', Typed::string($skill['title']), $result->status, $table, (int)$id),
                $result->isSuccess()
                    ? 'Report stored - see the Skills backend module.'
                    : $result->output,
                $result->isSuccess() ? ContextualFeedbackSeverity::OK : ContextualFeedbackSeverity::WARNING
            );
        }
    }

    /**
     * @param int|string $id
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_afterDatabaseOperations(string $status, string $table, $id, array $fieldArray, DataHandler $dataHandler): void
    {
        if ($status !== 'new' || self::$autoWorkflowRunning) {
            return;
        }
        $workspaceId = (int)$dataHandler->BE_USER->workspace;
        if ($workspaceId <= 0 || !BackendUtility::isTableWorkspaceEnabled($table)) {
            return;
        }
        $workspace = BackendUtility::getRecord('sys_workspace', $workspaceId);
        if ($workspace === null
            || !(bool)($workspace['tx_skillflow_auto_workflow'] ?? false)
            || Typed::int($workspace['tx_skillflow_auto_workflow_stage'] ?? 0) <= 0
        ) {
            return;
        }
        $recordUid = Typed::int($dataHandler->substNEWwithIDs[$id] ?? 0);
        if ($recordUid <= 0) {
            return;
        }
        $this->pendingAutoWorkflow[] = [
            'table' => $table,
            'uid' => $recordUid,
            'stage' => Typed::int($workspace['tx_skillflow_auto_workflow_stage']),
        ];
    }

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        if ($this->pendingAutoWorkflow === [] || self::$autoWorkflowRunning) {
            return;
        }
        $commandMap = [];
        foreach ($this->pendingAutoWorkflow as $pending) {
            $commandMap[$pending['table']][$pending['uid']]['version'] = [
                'action' => 'setStage',
                'stageId' => $pending['stage'],
                'comment' => 'Automatically sent to stage by the Skills auto-workflow for new elements.',
            ];
        }
        $this->pendingAutoWorkflow = [];

        self::$autoWorkflowRunning = true;
        try {
            $stageDataHandler = GeneralUtility::makeInstance(DataHandler::class);
            $stageDataHandler->start([], $commandMap, $dataHandler->BE_USER);
            $stageDataHandler->process_cmdmap();
        } finally {
            self::$autoWorkflowRunning = false;
        }
    }

    private function notify(string $title, string $message, ContextualFeedbackSeverity $severity): void
    {
        $flashMessage = GeneralUtility::makeInstance(
            FlashMessage::class,
            mb_substr($message, 0, 500),
            $title,
            $severity,
            true
        );
        GeneralUtility::makeInstance(FlashMessageService::class)
            ->getMessageQueueByIdentifier()
            ->enqueue($flashMessage);
    }
}
