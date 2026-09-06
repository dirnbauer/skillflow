<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillflow\Runner\EngineResolver;
use Webconsulting\Skillflow\Service\EnvironmentGuard;
use Webconsulting\Skillflow\Service\SkillExecutionService;
use Webconsulting\Skillflow\Service\SkillFinder;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Runs nr_llm-managed skills in page/workspace review workflows.
 *
 * Skill sources and activation are managed in nr_llm.
 */
final class SkillsModuleController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ComponentFactory $componentFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly SkillFinder $skillFinder,
        private readonly SkillExecutionService $skillExecutionService,
        private readonly EnvironmentGuard $environmentGuard,
        private readonly EngineResolver $engineResolver,
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle('Skill workflows');
        $body = Typed::stringKeyedArray($request->getParsedBody());
        $action = Typed::string($body['action'] ?? $request->getQueryParams()['action'] ?? '') ?: 'index';

        if ($request->getMethod() === 'POST') {
            match ($action) {
                'run', 'runPageSkills' => $this->runAction($request, $moduleTemplate, $action === 'runPageSkills'),
                default => null,
            };
        } elseif ($action === 'showRun') {
            return $this->renderRun($request, $moduleTemplate);
        }
        return $this->renderIndex($request, $moduleTemplate);
    }

    private function renderIndex(ServerRequestInterface $request, ModuleTemplate $moduleTemplate): ResponseInterface
    {
        GeneralUtility::makeInstance(PageRenderer::class)->addCssFile('EXT:skillflow/Resources/Public/Css/module.css');
        $pageUid = Typed::int($request->getQueryParams()['id'] ?? 0);
        $page = $this->readPage($pageUid);
        $pageUid = $page !== null ? $pageUid : 0;
        $assigned = $pageUid > 0 ? $this->skillFinder->findSkillsForPage($pageUid) : [];
        $moduleUri = (string)$this->uriBuilder->buildUriFromRoute('content_skillflow', $pageUid > 0 ? ['id' => $pageUid] : []);
        $skills = [];
        $titles = [];
        foreach ($this->skillFinder->findAllSkills(true) as $skill) {
            $titles[Typed::int($skill['uid'])] = Typed::string($skill['name'] ?? $skill['title'] ?? '');
            if (!(bool)($skill['hidden'] ?? false) && (bool)($skill['enabled'] ?? false) && !(bool)($skill['orphaned'] ?? false)) {
                $skills[] = $skill;
            }
        }

        $this->skillExecutionService->failStaleRuns();
        $runs = array_values(array_filter($this->skillFinder->findRecentRuns(25), $this->canReadRun(...)));
        foreach ($runs as &$run) {
            $run['skillTitle'] = Typed::string($run['skill_name'] ?? '')
                ?: ($titles[Typed::int($run['skill'])] ?? ('#' . Typed::int($run['skill'])));
            $run['createdFormatted'] = date('Y-m-d H:i', Typed::int($run['crdate']));
            $run['showUri'] = (string)$this->uriBuilder->buildUriFromRoute('content_skillflow', [
                'action' => 'showRun',
                'run' => Typed::int($run['uid']),
            ]);
        }
        unset($run);

        $nrLlmSkillsUri = '';
        if ($this->getBackendUser()->isAdmin()) {
            $nrLlmSkillsUri = (string)$this->uriBuilder->buildUriFromRoute('nrllm_skills');
            $button = $this->componentFactory->createLinkButton()
                ->setHref($nrLlmSkillsUri)
                ->setTitle('Manage skill sources in nr_llm')
                ->setShowLabelText(true)
                ->setIcon(GeneralUtility::makeInstance(IconFactory::class)->getIcon('module-nrllm-skill', IconSize::SMALL));
            $moduleTemplate->getDocHeaderComponent()->getButtonBar()->addButton($button, ButtonBar::BUTTON_POSITION_LEFT, 1);
        }

        $moduleTemplate->assignMultiple([
            'moduleUri' => $moduleUri,
            'nrLlmSkillsUri' => $nrLlmSkillsUri,
            'executionBlockReason' => $this->environmentGuard->getBlockReason(),
            'skills' => $skills,
            'runs' => $runs,
            'currentWorkspace' => (int)$this->getBackendUser()->workspace,
            'currentPageUid' => $pageUid,
            'currentPageTitle' => Typed::string($page['title'] ?? ''),
            'hasCurrentPage' => $page !== null,
            'assignedSkillsCount' => count($assigned),
            'engines' => array_keys($this->engineResolver->getRegisteredEngines()),
        ]);
        return $moduleTemplate->renderResponse('SkillsModule/Index');
    }

    private function renderRun(ServerRequestInterface $request, ModuleTemplate $moduleTemplate): ResponseInterface
    {
        $runUid = Typed::int($request->getQueryParams()['run'] ?? 0);
        $run = $this->skillFinder->findRunByUid($runUid);
        if ($run === null || !$this->canReadRun($run)) {
            $moduleTemplate->addFlashMessage('Run ' . $runUid . ' not found.', 'Not found', ContextualFeedbackSeverity::ERROR);
            return $this->renderIndex($request, $moduleTemplate);
        }
        $skill = $this->skillFinder->findSkillByUid(Typed::int($run['skill']));
        $targetPageUri = Typed::string($run['target_table']) === 'pages' && Typed::int($run['target_uid']) > 0
            ? (string)$this->uriBuilder->buildUriFromRoute('content_skillflow', ['id' => Typed::int($run['target_uid'])])
            : '';
        $moduleTemplate->assignMultiple([
            'moduleUri' => $targetPageUri ?: (string)$this->uriBuilder->buildUriFromRoute('content_skillflow'),
            'run' => $run,
            'skillTitle' => Typed::string($run['skill_name'] ?? '')
                ?: (Typed::string($skill['name'] ?? $skill['title'] ?? '') ?: ('#' . Typed::int($run['skill']))),
            'createdFormatted' => date('Y-m-d H:i:s', Typed::int($run['crdate'])),
            'targetPageUri' => $targetPageUri,
        ]);
        return $moduleTemplate->renderResponse('SkillsModule/Run');
    }

    private function runAction(ServerRequestInterface $request, ModuleTemplate $moduleTemplate, bool $assigned): void
    {
        $body = Typed::stringKeyedArray($request->getParsedBody());
        $skillUid = Typed::int($body['skill'] ?? 0);
        $pageUid = Typed::int($body['page'] ?? 0);
        if ((!$assigned && $skillUid <= 0) || $pageUid <= 0) {
            $moduleTemplate->addFlashMessage('Please select a skill and provide a page uid.', 'Missing input', ContextualFeedbackSeverity::WARNING);
            return;
        }
        if ($this->readPage($pageUid) === null) {
            $moduleTemplate->addFlashMessage('You do not have access to this page.', 'Access denied', ContextualFeedbackSeverity::ERROR);
            return;
        }
        $skillUids = $assigned
            ? array_map(static fn (array $skill): int => Typed::int($skill['uid']), $this->skillFinder->findSkillsForPage($pageUid))
            : [$skillUid];
        if ($skillUids === []) {
            $moduleTemplate->addFlashMessage('No active nr_llm skills are assigned to page ' . $pageUid . '.', 'Nothing to run', ContextualFeedbackSeverity::INFO);
            return;
        }
        foreach ($skillUids as $uid) {
            $this->executeAndReport($moduleTemplate, $uid, $pageUid, Typed::string($body['instructions'] ?? ''), Typed::string($body['engine'] ?? ''));
        }
    }

    private function executeAndReport(ModuleTemplate $moduleTemplate, int $skillUid, int $pageUid, string $instructions, string $engine): void
    {
        $result = $this->skillExecutionService->runSkillOnRecord($skillUid, 'pages', $pageUid, (int)$this->getBackendUser()->workspace, 0, $instructions, $engine);
        $skill = $this->skillFinder->findSkillByUid($skillUid);
        $severity = $result->isSuccess() ? ContextualFeedbackSeverity::OK
            : ($result->status === 'pending' ? ContextualFeedbackSeverity::INFO : ContextualFeedbackSeverity::WARNING);
        $message = $result->isSuccess() ? 'Report stored — see Recent runs.' : mb_substr($result->output, 0, 500);
        if ($result->verdict !== '') {
            $message = 'Verdict: ' . $result->verdict . ($result->score >= 0 ? ' (' . $result->score . '/100)' : '') . ' — ' . $message;
        }
        $moduleTemplate->addFlashMessage(
            $message,
            sprintf('Skill "%s" on page %d: %s', Typed::string($skill['name'] ?? $skill['title'] ?? '') ?: (string)$skillUid, $pageUid, $result->status),
            $severity,
        );
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No backend user available', 1760000050);
        }
        return $backendUser;
    }

    /** @return array<string, mixed>|null */
    private function readPage(int $pageUid): ?array
    {
        if ($pageUid <= 0) {
            return null;
        }
        $page = BackendUtility::readPageAccess($pageUid, $this->getBackendUser()->getPagePermsClause(1));
        return $page === false ? null : Typed::stringKeyedArray($page);
    }

    /** @param array<string, mixed> $run */
    private function canReadRun(array $run): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser->isAdmin()) {
            return true;
        }
        // Reports can contain draft content. Editors see reports for their
        // current workspace and only records in their readable page mounts.
        if (Typed::int($run['workspace_uid'] ?? 0) !== (int)$backendUser->workspace) {
            return false;
        }
        $table = Typed::string($run['target_table'] ?? '');
        $recordUid = Typed::int($run['target_uid'] ?? 0);
        if ($table === 'pages') {
            return $this->readPage($recordUid) !== null;
        }
        $tables = Typed::stringKeyedArray($GLOBALS['TCA'] ?? null);
        if ($recordUid <= 0 || !isset($tables[$table])) {
            return false;
        }
        $record = BackendUtility::getRecord($table, $recordUid, 'pid');
        return $this->readPage(Typed::int($record['pid'] ?? 0)) !== null;
    }
}
