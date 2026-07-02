<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillflow\Runner\EngineResolver;
use Webconsulting\Skillflow\Service\EnvironmentGuard;
use Webconsulting\Skillflow\Service\RepositoryImportService;
use Webconsulting\Skillflow\Service\SkillExecutionService;
use Webconsulting\Skillflow\Service\SkillFinder;
use Webconsulting\Skillflow\Service\SkillImportService;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Backend module "Skills": list and edit skills, manage repositories,
 * import/sync, run skills against pages and inspect run reports.
 */
final class SkillsModuleController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly SkillFinder $skillFinder,
        private readonly SkillImportService $skillImportService,
        private readonly RepositoryImportService $repositoryImportService,
        private readonly SkillExecutionService $skillExecutionService,
        private readonly EnvironmentGuard $environmentGuard,
        private readonly EngineResolver $engineResolver,
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle('Skills');

        $parsedBody = Typed::stringKeyedArray($request->getParsedBody());
        $action = Typed::string($parsedBody['action'] ?? $request->getQueryParams()['action'] ?? null) ?: 'index';
        if ($request->getMethod() === 'POST') {
            match ($action) {
                'scanFolder' => $this->scanFolderAction($moduleTemplate),
                'syncRepository' => $this->syncRepositoryAction($request, $moduleTemplate),
                'run' => $this->runAction($request, $moduleTemplate),
                'runPageSkills' => $this->runPageSkillsAction($request, $moduleTemplate),
                'recheck' => $this->recheckAction($moduleTemplate),
                default => null,
            };
        } elseif ($action === 'showRun') {
            return $this->renderRun($request, $moduleTemplate);
        }

        return $this->renderIndex($request, $moduleTemplate);
    }

    private function renderIndex(ServerRequestInterface $request, ModuleTemplate $moduleTemplate): ResponseInterface
    {
        GeneralUtility::makeInstance(PageRenderer::class)
            ->addCssFile('EXT:skillflow/Resources/Public/Css/module.css');

        // The page currently selected in the page tree (?id=). Pre-fills the run form.
        $currentPageUid = Typed::int($request->getQueryParams()['id'] ?? 0);
        $currentPage = $this->skillFinder->findPageByUid($currentPageUid);
        $assignedSkills = $currentPageUid > 0 ? $this->skillFinder->findSkillsForPage($currentPageUid) : [];

        // Keep the page-tree context on the form action so a POST re-render stays on the same page.
        $moduleUri = (string)$this->uriBuilder->buildUriFromRoute(
            'content_skillflow',
            $currentPageUid > 0 ? ['id' => $currentPageUid] : []
        );
        $skills = $this->skillFinder->findAllSkills(true);
        $reviewSummary = ['danger' => 0, 'warning' => 0, 'unchecked' => 0];
        foreach ($skills as &$skill) {
            $skill['editUri'] = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => ['tx_skillflow_skill' => [Typed::int($skill['uid']) => 'edit']],
                'returnUrl' => $moduleUri,
            ]);
            $skill['descriptionShort'] = $this->crop(Typed::string($skill['description']), 120);
            $skill['review'] = $this->buildReviewView(Typed::string($skill['check_report'] ?? ''));
            if ($skill['review']['unchecked']) {
                $reviewSummary['unchecked']++;
            } elseif ($skill['review']['level'] === 'danger') {
                $reviewSummary['danger']++;
            } elseif ($skill['review']['level'] === 'warning') {
                $reviewSummary['warning']++;
            }
        }
        unset($skill);

        $repositories = $this->skillFinder->findAllRepositories();
        foreach ($repositories as &$repository) {
            $repository['editUri'] = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => ['tx_skillflow_repository' => [Typed::int($repository['uid']) => 'edit']],
                'returnUrl' => $moduleUri,
            ]);
            $repository['lastSyncedFormatted'] = Typed::int($repository['last_synced']) > 0
                ? date('Y-m-d H:i', Typed::int($repository['last_synced']))
                : '–';
            $repository['lastErrorShort'] = $this->crop(Typed::string($repository['last_error']), 80);
        }
        unset($repository);

        $skillTitles = [];
        foreach ($skills as $skill) {
            $skillTitles[Typed::int($skill['uid'])] = Typed::string($skill['title']);
        }
        $this->skillExecutionService->failStaleRuns();
        $runs = $this->skillFinder->findRecentRuns(25);
        foreach ($runs as &$run) {
            $run['skillTitle'] = $skillTitles[Typed::int($run['skill'])] ?? ('#' . Typed::int($run['skill']));
            $run['createdFormatted'] = date('Y-m-d H:i', Typed::int($run['crdate']));
            $run['showUri'] = (string)$this->uriBuilder->buildUriFromRoute('content_skillflow', [
                'action' => 'showRun',
                'run' => Typed::int($run['uid']),
            ]);
        }
        unset($run);

        $newSkillUri = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['tx_skillflow_skill' => [0 => 'new']],
            'returnUrl' => $moduleUri,
        ]);
        $newRepositoryUri = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => ['tx_skillflow_repository' => [0 => 'new']],
            'returnUrl' => $moduleUri,
        ]);

        $isAdmin = $this->getBackendUser()->isAdmin();
        $this->registerDocHeaderButtons($moduleTemplate, $moduleUri, $newSkillUri, $newRepositoryUri, $currentPageUid, $isAdmin);

        $moduleTemplate->assignMultiple([
            'moduleUri' => $moduleUri,
            'isAdmin' => $isAdmin,
            'executionBlockReason' => $this->environmentGuard->getBlockReason(),
            'skills' => $skills,
            'repositories' => $repositories,
            'runs' => $runs,
            'skillsFolder' => $this->skillImportService->getConfiguredFolder(),
            'currentWorkspace' => (int)$this->getBackendUser()->workspace,
            'currentPageUid' => $currentPageUid,
            'currentPageTitle' => Typed::string($currentPage['title'] ?? null),
            'hasCurrentPage' => $currentPage !== null,
            'assignedSkills' => $assignedSkills,
            'assignedSkillsCount' => count($assignedSkills),
            'newSkillUri' => $newSkillUri,
            'newRepositoryUri' => $newRepositoryUri,
            'engines' => array_keys($this->engineResolver->getRegisteredEngines()),
            'reviewSummary' => $reviewSummary,
        ]);
        return $moduleTemplate->renderResponse('SkillsModule/Index');
    }

    /**
     * Primary actions live in the module doc header (standard TYPO3 placement),
     * so the body can focus on the run form and reports.
     */
    private function registerDocHeaderButtons(
        ModuleTemplate $moduleTemplate,
        string $moduleUri,
        string $newSkillUri,
        string $newRepositoryUri,
        int $currentPageUid,
        bool $isAdmin,
    ): void {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $iconFactory = GeneralUtility::makeInstance(IconFactory::class);

        $newSkill = $buttonBar->makeLinkButton()
            ->setHref($newSkillUri)
            ->setTitle($this->lll('skills.new'))
            ->setShowLabelText(true)
            ->setIcon($iconFactory->getIcon('actions-plus', IconSize::SMALL));
        $buttonBar->addButton($newSkill, ButtonBar::BUTTON_POSITION_LEFT, 1);

        if ($isAdmin) {
            $newRepository = $buttonBar->makeLinkButton()
                ->setHref($newRepositoryUri)
                ->setTitle($this->lll('repositories.new'))
                ->setShowLabelText(true)
                ->setIcon($iconFactory->getIcon('actions-database', IconSize::SMALL));
            $buttonBar->addButton($newRepository, ButtonBar::BUTTON_POSITION_LEFT, 2);
        }

        $reload = $buttonBar->makeLinkButton()
            ->setHref($moduleUri)
            ->setTitle($this->getLanguageService()->sL('LLL:EXT:core/Resources/Private/Language/locallang_core.xlf:labels.reload'))
            ->setIcon($iconFactory->getIcon('actions-refresh', IconSize::SMALL));
        $buttonBar->addButton($reload, ButtonBar::BUTTON_POSITION_RIGHT);

        $shortcut = $buttonBar->makeShortcutButton()
            ->setRouteIdentifier('content_skillflow')
            ->setDisplayName($this->lll('module.headline'))
            ->setArguments($currentPageUid > 0 ? ['id' => $currentPageUid] : []);
        $buttonBar->addButton($shortcut, ButtonBar::BUTTON_POSITION_RIGHT);
    }

    private function renderRun(ServerRequestInterface $request, ModuleTemplate $moduleTemplate): ResponseInterface
    {
        $runUid = Typed::int($request->getQueryParams()['run'] ?? 0);
        $run = $this->skillFinder->findRunByUid($runUid);
        if ($run === null) {
            $moduleTemplate->addFlashMessage('Run ' . $runUid . ' not found.', 'Not found', ContextualFeedbackSeverity::ERROR);
            return $this->renderIndex($request, $moduleTemplate);
        }
        $skill = $this->skillFinder->findSkillByUid(Typed::int($run['skill']));
        $targetTable = Typed::string($run['target_table']);
        $targetUid = Typed::int($run['target_uid']);
        $targetPageUri = ($targetTable === 'pages' && $targetUid > 0)
            ? (string)$this->uriBuilder->buildUriFromRoute('content_skillflow', ['id' => $targetUid])
            : '';
        $moduleTemplate->assignMultiple([
            'moduleUri' => $targetPageUri ?: (string)$this->uriBuilder->buildUriFromRoute('content_skillflow'),
            'run' => $run,
            'skillTitle' => Typed::string($skill['title'] ?? null) ?: ('#' . Typed::int($run['skill'])),
            'createdFormatted' => date('Y-m-d H:i:s', Typed::int($run['crdate'])),
            'targetPageUri' => $targetPageUri,
        ]);
        return $moduleTemplate->renderResponse('SkillsModule/Run');
    }

    private function recheckAction(ModuleTemplate $moduleTemplate): void
    {
        if (!$this->getBackendUser()->isAdmin()) {
            $this->denied($moduleTemplate);
            return;
        }
        $checked = $this->skillImportService->recheckAllSkills();
        $moduleTemplate->addFlashMessage(
            sprintf('Re-scanned %d skill(s) for security patterns and license compatibility.', $checked),
            'Review finished',
            ContextualFeedbackSeverity::OK
        );
    }

    private function scanFolderAction(ModuleTemplate $moduleTemplate): void
    {
        if (!$this->getBackendUser()->isAdmin()) {
            $this->denied($moduleTemplate);
            return;
        }
        $result = $this->skillImportService->importFromConfiguredFolder();
        $moduleTemplate->addFlashMessage(
            $result->summary(),
            'Folder import finished',
            $result->errors === [] ? ContextualFeedbackSeverity::OK : ContextualFeedbackSeverity::WARNING
        );
    }

    private function syncRepositoryAction(ServerRequestInterface $request, ModuleTemplate $moduleTemplate): void
    {
        if (!$this->getBackendUser()->isAdmin()) {
            $this->denied($moduleTemplate);
            return;
        }
        $repositoryUid = Typed::int(Typed::stringKeyedArray($request->getParsedBody())['repository'] ?? 0);
        try {
            $result = $this->repositoryImportService->sync($repositoryUid);
            $moduleTemplate->addFlashMessage(
                $result->summary(),
                'Repository sync finished',
                $result->errors === [] ? ContextualFeedbackSeverity::OK : ContextualFeedbackSeverity::WARNING
            );
        } catch (\Throwable $e) {
            $moduleTemplate->addFlashMessage($e->getMessage(), 'Repository sync failed', ContextualFeedbackSeverity::ERROR);
        }
    }

    private function runAction(ServerRequestInterface $request, ModuleTemplate $moduleTemplate): void
    {
        $body = Typed::stringKeyedArray($request->getParsedBody());
        $skillUid = Typed::int($body['skill'] ?? 0);
        $pageUid = Typed::int($body['page'] ?? 0);
        $instructions = Typed::string($body['instructions'] ?? '');
        $engine = Typed::string($body['engine'] ?? '');
        if ($skillUid <= 0 || $pageUid <= 0) {
            $moduleTemplate->addFlashMessage('Please select a skill and provide a page uid.', 'Missing input', ContextualFeedbackSeverity::WARNING);
            return;
        }
        $this->executeAndReport($moduleTemplate, $skillUid, $pageUid, $instructions, $engine);
    }

    private function runPageSkillsAction(ServerRequestInterface $request, ModuleTemplate $moduleTemplate): void
    {
        $body = Typed::stringKeyedArray($request->getParsedBody());
        $pageUid = Typed::int($body['page'] ?? 0);
        $instructions = Typed::string($body['instructions'] ?? '');
        $engine = Typed::string($body['engine'] ?? '');
        $skills = $pageUid > 0 ? $this->skillFinder->findSkillsForPage($pageUid) : [];
        if ($skills === []) {
            $moduleTemplate->addFlashMessage(
                'No skills are assigned to page ' . $pageUid . ' (page properties > Skills tab).',
                'Nothing to run',
                ContextualFeedbackSeverity::INFO
            );
            return;
        }
        foreach ($skills as $skill) {
            $this->executeAndReport($moduleTemplate, Typed::int($skill['uid']), $pageUid, $instructions, $engine);
        }
    }

    private function executeAndReport(ModuleTemplate $moduleTemplate, int $skillUid, int $pageUid, string $instructions = '', string $engine = ''): void
    {
        $result = $this->skillExecutionService->runSkillOnRecord(
            $skillUid,
            'pages',
            $pageUid,
            (int)$this->getBackendUser()->workspace,
            0,
            $instructions,
            $engine
        );
        $skill = $this->skillFinder->findSkillByUid($skillUid);
        $severity = match (true) {
            $result->isSuccess() => ContextualFeedbackSeverity::OK,
            $result->status === 'pending' => ContextualFeedbackSeverity::INFO,
            default => ContextualFeedbackSeverity::WARNING,
        };
        $message = $result->isSuccess() ? 'Report stored — see "Recent runs".' : mb_substr($result->output, 0, 500);
        if ($result->verdict !== '') {
            $message = 'Verdict: ' . $result->verdict . ($result->score >= 0 ? ' (' . $result->score . '/100)' : '') . ' — ' . $message;
        }
        $moduleTemplate->addFlashMessage(
            $message,
            sprintf('Skill "%s" on page %d: %s', Typed::string($skill['title'] ?? null) ?: (string)$skillUid, $pageUid, $result->status),
            $severity
        );
    }

    private function crop(string $text, int $maxCharacters): string
    {
        $text = trim($text);
        return mb_strlen($text) > $maxCharacters ? mb_substr($text, 0, $maxCharacters) . '…' : $text;
    }

    /**
     * Decode the stored check_report JSON into a template-friendly view:
     * the badge level, findings list, license assessment, and code flag.
     *
     * @return array{unchecked: bool, level: string, hasCode: bool, findingCount: int, findings: list<array<string, string>>, license: array<string, string>, licenseWarning: bool}
     */
    private function buildReviewView(string $checkReportJson): array
    {
        $empty = [
            'unchecked' => true,
            'level' => 'none',
            'hasCode' => false,
            'findingCount' => 0,
            'findings' => [],
            'license' => [],
            'licenseWarning' => false,
        ];
        if (trim($checkReportJson) === '') {
            return $empty;
        }
        $report = json_decode($checkReportJson, true);
        if (!is_array($report)) {
            return $empty;
        }
        $license = $this->stringifyMap(is_array($report['license'] ?? null) ? $report['license'] : []);
        $findings = [];
        foreach (is_array($report['findings'] ?? null) ? $report['findings'] : [] as $finding) {
            if (is_array($finding)) {
                $findings[] = $this->stringifyMap($finding);
            }
        }
        $hasCode = (bool)($report['hasCode'] ?? false);
        return [
            'unchecked' => false,
            'level' => Typed::string($report['level'] ?? 'none'),
            'hasCode' => $hasCode,
            'findingCount' => count($findings),
            'findings' => $findings,
            'license' => $license,
            // The license only warrants a badge when there is code to reuse.
            'licenseWarning' => $hasCode && ($license['status'] ?? 'compatible') !== 'compatible',
        ];
    }

    /**
     * @param array<array-key, mixed> $map
     * @return array<string, string>
     */
    private function stringifyMap(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            $out[(string)$key] = Typed::string($value);
        }
        return $out;
    }

    private function denied(ModuleTemplate $moduleTemplate): void
    {
        $moduleTemplate->addFlashMessage('This action requires administrator privileges.', 'Access denied', ContextualFeedbackSeverity::ERROR);
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No backend user available', 1760000050);
        }
        return $backendUser;
    }

    private function getLanguageService(): LanguageService
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            throw new \RuntimeException('No language service available', 1760000051);
        }
        return $languageService;
    }

    private function lll(string $key): string
    {
        return $this->getLanguageService()->sL(
            'LLL:EXT:skillflow/Resources/Private/Language/locallang.xlf:' . $key
        );
    }
}
