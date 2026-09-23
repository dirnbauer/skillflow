<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Dto\Breadcrumb\BreadcrumbNode;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Pagination\ArrayPaginator;
use TYPO3\CMS\Core\Pagination\SimplePagination;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use Webconsulting\Skillflow\Domain\AbilityFinding;
use Webconsulting\Skillflow\Domain\RunStatus;
use Webconsulting\Skillflow\Domain\SkillRunResult;
use Webconsulting\Skillflow\Runner\EngineResolver;
use Webconsulting\Skillflow\Service\EnvironmentGuard;
use Webconsulting\Skillflow\Service\SkillAbilityResolver;
use Webconsulting\Skillflow\Service\SkillExecutionService;
use Webconsulting\Skillflow\Service\SkillFinder;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Content → Skills: run nr_llm-managed skills on the page selected in the
 * page tree and read the reports. Skill sources and activation stay in nr_llm.
 */
#[AsController]
final readonly class SkillsModuleController
{
    private const string ROUTE = 'content_skillflow';
    private const string DOMAIN = 'skillflow.messages';
    private const int RUNS_PER_PAGE = 20;

    /** Newest runs scanned for one listing; older reports stay in the Records module. */
    private const int RUN_SCAN_LIMIT = 500;

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
        private UriBuilder $uriBuilder,
        private SkillFinder $skillFinder,
        private SkillExecutionService $skillExecutionService,
        private EnvironmentGuard $environmentGuard,
        private EngineResolver $engineResolver,
        private SkillAbilityResolver $abilityResolver,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $body = Typed::stringKeyedArray($request->getParsedBody());
        $action = Typed::string($body['action'] ?? $request->getQueryParams()['action'] ?? '');

        return match (true) {
            $request->getMethod() === 'POST' && $action === 'run' => $this->runAction($request, false),
            $request->getMethod() === 'POST' && $action === 'runPageSkills' => $this->runAction($request, true),
            $action === 'showRun' => $this->showRunAction($request),
            default => $this->indexAction($request),
        };
    }

    private function indexAction(ServerRequestInterface $request, ?ModuleTemplate $view = null): ResponseInterface
    {
        $view ??= $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->label('module.title'));
        $query = $request->getQueryParams();
        $page = $this->readPage(Typed::int($query['id'] ?? 0));
        $pageUid = Typed::int($page['uid'] ?? 0);
        $allPages = $page === null || Typed::string($query['scope'] ?? '') === 'all';

        $docHeader = $view->getDocHeaderComponent();
        if ($page !== null) {
            $docHeader->setPageBreadcrumb($page);
        }
        $docHeader->setShortcutContext(self::ROUTE, $this->label('module.title'), array_filter(['id' => $pageUid]));
        $this->addSkillSourcesButton($view);

        $this->skillExecutionService->failStaleRuns();
        $titles = $this->skillTitles();
        $returnUrl = $this->returnUrl($request);
        $runs = $this->readableRuns($allPages ? null : $pageUid);
        $skillGroups = $page !== null ? $this->skillGroups($pageUid) : [];
        $currentPage = max(1, Typed::int($query['page'] ?? 1));
        $paginator = new ArrayPaginator($runs, $currentPage, self::RUNS_PER_PAGE);

        $view->assignMultiple([
            'page' => $page,
            'pageUid' => $pageUid,
            'allPages' => $allPages,
            'formUri' => $this->moduleUri(array_filter(['id' => $pageUid])),
            'pageScopeUri' => $this->moduleUri(['id' => $pageUid]),
            'allPagesUri' => $this->moduleUri(array_filter(['id' => $pageUid, 'scope' => 'all'])),
            'editPageUri' => $page !== null ? $this->editRecordUri('pages', $pageUid, $returnUrl) : '',
            'executionBlockReason' => $this->environmentGuard->getBlockReason(),
            'skillGroups' => $skillGroups,
            'skillAbilities' => $this->abilityOverview(array_merge(...array_column($skillGroups, 'skills')), $returnUrl),
            'abilitiesAvailable' => $this->abilityResolver->isAvailable(),
            'assignedSkills' => $page !== null ? $this->skillFinder->findSkillsForPage($pageUid) : [],
            'engines' => array_keys($this->engineResolver->getRegisteredEngines()),
            'workspaceTitle' => $this->workspaceTitle($this->backendUser()->workspace),
            'isAdmin' => $this->backendUser()->isAdmin(),
            'skillSourcesUri' => $this->skillSourcesUri(),
            'runs' => array_map(
                fn(array $run): array => $this->runListItem($run, $titles, $returnUrl),
                array_slice($runs, $paginator->getKeyOfFirstPaginatedItem(), self::RUNS_PER_PAGE),
            ),
            'runCount' => count($runs),
            'paginator' => $paginator,
            'pagination' => new SimplePagination($paginator),
            'paginationUri' => $this->moduleUri(array_filter(['id' => $pageUid, 'scope' => $allPages && $pageUid > 0 ? 'all' : null])),
            'dateFormat' => Typed::string($GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] ?? 'Y-m-d'),
            'timeFormat' => Typed::string($GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'] ?? 'H:i'),
        ]);

        return $view->renderResponse('SkillsModule/Index');
    }

    private function showRunAction(ServerRequestInterface $request): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $runUid = Typed::int($request->getQueryParams()['run'] ?? 0);
        $run = $this->skillFinder->findRunByUid($runUid);
        if ($run === null || !$this->canReadRun($run)) {
            $view->addFlashMessage(
                $this->label('flash.runNotFound.message', [$runUid]),
                $this->label('flash.runNotFound.title'),
                ContextualFeedbackSeverity::ERROR,
                false,
            );

            return $this->indexAction($request, $view);
        }

        $returnUrl = $this->returnUrl($request);
        $item = $this->runListItem($run, $this->skillTitles(), $returnUrl);
        $skill = $this->skillFinder->findSkillByUid(Typed::int($run['skill'] ?? 0));
        $title = $this->label('run.title', [$runUid]);
        $view->setTitle($title, $this->label('module.title'));
        $docHeader = $view->getDocHeaderComponent();
        $targetPage = $item['targetPageUid'] > 0 ? $this->readPage($item['targetPageUid']) : null;
        if ($targetPage !== null) {
            $docHeader->setPageBreadcrumb($targetPage);
        }
        $docHeader->addBreadcrumbSuffixNode(new BreadcrumbNode('skillflow-run', $title, 'skillflow-run'));
        $docHeader->setShortcutContext(self::ROUTE, $title, ['action' => 'showRun', 'run' => $runUid]);
        $returnUri = $this->moduleUri(array_filter(['id' => $item['targetPageUid']]));
        $view->addButtonToButtonBar($this->componentFactory->createCloseButton($returnUri), ButtonBar::BUTTON_POSITION_LEFT, 1);
        $externalUrl = Typed::string($run['external_url'] ?? '');
        if ($externalUrl !== '') {
            $view->addButtonToButtonBar(
                $this->componentFactory->createLinkButton()
                    ->setHref($externalUrl)
                    ->setTitle($this->label('run.openEngine'))
                    ->setShowLabelText(true)
                    ->setIcon($this->iconFactory->getIcon('actions-open', IconSize::SMALL)),
                ButtonBar::BUTTON_POSITION_LEFT,
                2,
            );
        }

        $view->assignMultiple([
            'run' => $run,
            'item' => $item,
            'title' => $title,
            'returnUri' => $returnUri,
            'workspaceTitle' => $this->workspaceTitle(Typed::int($run['workspace_uid'] ?? 0)),
            'stageTitle' => $this->stageTitle(Typed::int($run['stage_uid'] ?? 0)),
            'resultJson' => $this->prettyJson(Typed::string($run['result_json'] ?? '')),
            'skillAbilities' => $skill !== null ? ($this->abilityOverview([$skill], $returnUrl)[0]['abilities'] ?? []) : [],
            'dateFormat' => Typed::string($GLOBALS['TYPO3_CONF_VARS']['SYS']['ddmmyy'] ?? 'Y-m-d'),
            'timeFormat' => Typed::string($GLOBALS['TYPO3_CONF_VARS']['SYS']['hhmm'] ?? 'H:i'),
        ]);

        return $view->renderResponse('SkillsModule/Run');
    }

    /**
     * Executes the selected skill (or every skill assigned to the page) and
     * redirects, so a reload never runs a skill twice. A single run lands on
     * its report; several runs return to the page overview.
     */
    private function runAction(ServerRequestInterface $request, bool $assigned): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($request);
        $body = Typed::stringKeyedArray($request->getParsedBody());
        $skillUid = Typed::int($body['skill'] ?? 0);
        $pageUid = Typed::int($body['page'] ?? 0);
        $overviewUri = $this->moduleUri(array_filter(['id' => $pageUid]));

        if ((!$assigned && $skillUid <= 0) || $pageUid <= 0) {
            $view->addFlashMessage($this->label('flash.missingInput.message'), $this->label('flash.missingInput.title'), ContextualFeedbackSeverity::WARNING);
            return new RedirectResponse($overviewUri, 303);
        }
        if ($this->readPage($pageUid) === null) {
            $view->addFlashMessage($this->label('flash.accessDenied.message'), $this->label('flash.accessDenied.title'), ContextualFeedbackSeverity::ERROR);
            return new RedirectResponse($this->moduleUri(), 303);
        }

        $skillUids = $assigned
            ? array_map(static fn(array $skill): int => Typed::int($skill['uid']), $this->skillFinder->findSkillsForPage($pageUid))
            : [$skillUid];
        if ($skillUids === []) {
            $view->addFlashMessage($this->label('flash.nothingAssigned.message'), $this->label('flash.nothingAssigned.title'), ContextualFeedbackSeverity::INFO);
            return new RedirectResponse($overviewUri, 303);
        }

        $instructions = Typed::string($body['instructions'] ?? '');
        $engine = Typed::string($body['engine'] ?? '');
        $workspace = $this->backendUser()->workspace;
        $results = [];
        foreach ($skillUids as $uid) {
            $result = $this->skillExecutionService->runSkillOnRecord($uid, 'pages', $pageUid, $workspace, 0, $instructions, $engine);
            $results[] = $result;
            $this->reportResult($view, $uid, $result);
        }

        $single = count($results) === 1 ? $results[0] : null;
        return new RedirectResponse(
            $single !== null && $single->runUid > 0 ? $this->moduleUri(['action' => 'showRun', 'run' => $single->runUid]) : $overviewUri,
            303,
        );
    }

    private function reportResult(ModuleTemplate $view, int $skillUid, SkillRunResult $result): void
    {
        $status = $result->runStatus();
        $skillName = Typed::string($this->skillFinder->findSkillByUid($skillUid)['name'] ?? '') ?: '#' . $skillUid;
        $message = match ($status) {
            RunStatus::Success => $this->label('flash.run.success'),
            RunStatus::Pending, RunStatus::Running => $this->label('flash.run.pending'),
            RunStatus::Failed, RunStatus::Blocked => mb_substr($result->output, 0, 500),
        };
        if ($result->verdict !== '') {
            $message = $this->label('flash.run.verdict', [$result->verdict, $result->score >= 0 ? $result->score . '/100' : '–']) . ' ' . $message;
        }
        $view->addFlashMessage($message, $this->label('flash.run.title', [$skillName, $this->label('status.' . $status->value)]), $status->severity());
    }

    /**
     * The run form lists every available skill once: first those assigned to
     * the page, then those assigned to the current backend user, then the rest.
     *
     * @return list<array{label: string, skills: list<array<string, mixed>>}>
     */
    private function skillGroups(int $pageUid): array
    {
        $available = $this->skillFinder->findAllSkills();
        $pageSkills = $this->uidSet($this->skillFinder->findSkillsForPage($pageUid));
        $userSkills = $this->uidSet($this->skillFinder->findSkillsForBackendUser($this->backendUser()->getUserId() ?? 0));
        $groups = ['page' => [], 'user' => [], 'other' => []];
        foreach ($available as $skill) {
            $uid = Typed::int($skill['uid'] ?? 0);
            $groups[isset($pageSkills[$uid]) ? 'page' : (isset($userSkills[$uid]) ? 'user' : 'other')][] = $skill;
        }

        return array_values(array_filter([
            ['label' => $this->label('run.skill.group.page'), 'skills' => $groups['page']],
            ['label' => $this->label('run.skill.group.user'), 'skills' => $groups['user']],
            ['label' => $this->label($groups['page'] === [] && $groups['user'] === [] ? 'run.skill.group.all' : 'run.skill.group.other'), 'skills' => $groups['other']],
        ], static fn(array $group): bool => $group['skills'] !== []));
    }

    /**
     * @param list<array<string, mixed>> $skills
     * @return array<int, true>
     */
    private function uidSet(array $skills): array
    {
        return array_fill_keys(array_map(static fn(array $skill): int => Typed::int($skill['uid'] ?? 0), $skills), true);
    }

    /**
     * Runs the current user may read, newest first. Reports can contain draft
     * content, so editors see only their current workspace and records inside
     * their page mounts; the filter needs the target record, hence the scan limit.
     *
     * @return list<array<string, mixed>>
     */
    private function readableRuns(?int $pageUid): array
    {
        $backendUser = $this->backendUser();
        $runs = $this->skillFinder->findRuns(
            self::RUN_SCAN_LIMIT,
            $backendUser->isAdmin() ? null : $backendUser->workspace,
            $pageUid,
        );

        return array_values(array_filter($runs, $this->canReadRun(...)));
    }

    /**
     * @param array<string, mixed> $run
     * @param array<int, string> $titles
     * @return array{uid: int, crdate: int, skillTitle: string, skillDeleted: bool, skillUri: string, status: RunStatus, severity: ContextualFeedbackSeverity, statusLabel: string, verdict: string, score: int, engine: string, targetTable: string, targetUid: int, targetTitle: string, targetRecord: array<string, mixed>|null, targetPageUid: int, targetUri: string, showUri: string}
     */
    private function runListItem(array $run, array $titles, string $returnUrl): array
    {
        $table = Typed::string($run['target_table'] ?? '');
        $targetUid = Typed::int($run['target_uid'] ?? 0);
        $record = $targetUid > 0 ? BackendUtility::getRecord($table, $targetUid) : null;
        $targetPageUid = $table === 'pages' ? $targetUid : Typed::int($record['pid'] ?? 0);
        $status = RunStatus::fromValue($run['status'] ?? null);
        $skillUid = Typed::int($run['skill'] ?? 0);
        // Blocked and failed runs never reached a runner; they record "none".
        $engine = Typed::string($run['external_engine'] ?? '') ?: Typed::string($run['runner'] ?? '');

        return [
            'uid' => Typed::int($run['uid'] ?? 0),
            'crdate' => Typed::int($run['crdate'] ?? 0),
            'skillTitle' => $titles[$skillUid] ?? $this->deletedSkillReference($run, $skillUid),
            'skillDeleted' => !isset($titles[$skillUid]),
            'skillUri' => isset($titles[$skillUid]) ? $this->editSkillUri($skillUid, $returnUrl) : '',
            'status' => $status,
            'severity' => $status->severity(),
            'statusLabel' => $this->label('status.' . $status->value),
            'verdict' => Typed::string($run['verdict'] ?? ''),
            'score' => Typed::int($run['score'] ?? -1),
            'engine' => $engine === 'none' ? '' : $engine,
            'targetTable' => $table,
            'targetUid' => $targetUid,
            'targetTitle' => $record !== null ? BackendUtility::getRecordTitle($table, $record) : $table . ':' . $targetUid,
            'targetRecord' => $record,
            'targetPageUid' => $targetPageUid,
            'targetUri' => $targetPageUid > 0 ? $this->moduleUri(['id' => $targetPageUid]) : '',
            'showUri' => $this->moduleUri(['action' => 'showRun', 'run' => Typed::int($run['uid'] ?? 0)]),
        ];
    }

    /**
     * Names of every skill, including hidden, disabled and orphaned ones, so
     * older reports keep their skill's name.
     *
     * @return array<int, string>
     */
    private function skillTitles(): array
    {
        $titles = [];
        foreach ($this->skillFinder->findAllSkills(true) as $skill) {
            $titles[Typed::int($skill['uid'] ?? 0)] = Typed::string($skill['name'] ?? '');
        }

        return $titles;
    }

    /**
     * What a run recorded about a skill that no longer exists in nr_llm:
     * its name and identifier at run time (since 1.8.0), else its uid.
     *
     * @param array<string, mixed> $run
     */
    private function deletedSkillReference(array $run, int $skillUid): string
    {
        $name = Typed::string($run['skill_name'] ?? '');
        $identifier = Typed::string($run['skill_identifier'] ?? '');
        if ($name !== '' && $identifier !== '') {
            return $name . ' (' . $identifier . ')';
        }

        return $name ?: $identifier ?: ($skillUid > 0 ? '#' . $skillUid : '');
    }

    /** The nr_llm skill record, for users who may edit it. */
    private function editSkillUri(int $skillUid, string $returnUrl): string
    {
        return $skillUid > 0 && $this->backendUser()->check('tables_modify', 'tx_nrllm_skill')
            ? $this->editRecordUri('tx_nrllm_skill', $skillUid, $returnUrl)
            : '';
    }

    /**
     * The abilities each skill declares, checked for the current backend
     * user: "ok" (allowed as the MCP tool shown), "missing" or "denied".
     * Skills without abilities are left out.
     *
     * @param list<array<string, mixed>> $skills SkillFinder rows
     * @return list<array{uid: int, name: string, uri: string, abilities: list<array{name: string, tool: string, status: string, message: string}>}>
     */
    private function abilityOverview(array $skills, string $returnUrl): array
    {
        $overview = [];
        foreach ($skills as $skill) {
            $abilities = is_array($skill['abilities'] ?? null) ? array_values(array_filter($skill['abilities'], is_string(...))) : [];
            if ($abilities === []) {
                continue;
            }
            $findings = [];
            foreach ($this->abilityResolver->check($abilities, $this->backendUser()) as $finding) {
                $findings[$finding->ability] = $finding;
            }
            $rows = [];
            foreach ($abilities as $name) {
                $finding = $findings[$name] ?? null;
                $rows[] = [
                    'name' => $name,
                    'tool' => $finding === null ? ($this->abilityResolver->allowedTools([$name])[0] ?? '') : '',
                    'status' => match ($finding?->code) {
                        null => 'ok',
                        AbilityFinding::MISSING => 'missing',
                        default => 'denied',
                    },
                    'message' => $finding->message ?? '',
                ];
            }
            $uid = Typed::int($skill['uid'] ?? 0);
            $overview[] = [
                'uid' => $uid,
                'name' => Typed::string($skill['name'] ?? ''),
                'uri' => $this->editSkillUri($uid, $returnUrl),
                'abilities' => $rows,
            ];
        }

        return $overview;
    }

    private function addSkillSourcesButton(ModuleTemplate $view): void
    {
        $uri = $this->skillSourcesUri();
        if ($uri === '') {
            return;
        }
        $view->addButtonToButtonBar(
            $this->componentFactory->createLinkButton()
                ->setHref($uri)
                ->setTitle($this->label('skills.manage'))
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('module-nrllm-skill', IconSize::SMALL)),
            ButtonBar::BUTTON_POSITION_LEFT,
            1,
        );
    }

    /** nr_llm's skill module, for administrators only. */
    private function skillSourcesUri(): string
    {
        if (!$this->backendUser()->isAdmin()) {
            return '';
        }
        try {
            return (string)$this->uriBuilder->buildUriFromRoute('nrllm_skills');
        } catch (RouteNotFoundException) {
            return '';
        }
    }

    private function editRecordUri(string $table, int $uid, string $returnUrl): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [$table => [$uid => 'edit']],
            'returnUrl' => $returnUrl,
        ]);
    }

    private function returnUrl(ServerRequestInterface $request): string
    {
        $normalizedParams = $request->getAttribute('normalizedParams');

        return $normalizedParams instanceof NormalizedParams ? $normalizedParams->getRequestUri() : $this->moduleUri();
    }

    /** @param array<string, mixed> $parameters */
    private function moduleUri(array $parameters = []): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, $parameters);
    }

    private function workspaceTitle(int $workspaceUid): string
    {
        if ($workspaceUid === 0) {
            return $this->label('workspace.live');
        }

        return Typed::string(BackendUtility::getRecord('sys_workspace', $workspaceUid, 'title')['title'] ?? '') ?: '#' . $workspaceUid;
    }

    private function stageTitle(int $stageUid): string
    {
        if ($stageUid <= 0) {
            return '';
        }

        return Typed::string(BackendUtility::getRecord('sys_workspace_stage', $stageUid, 'title')['title'] ?? '') ?: '#' . $stageUid;
    }

    private function prettyJson(string $json): string
    {
        if (trim($json) === '') {
            return '';
        }
        try {
            return json_encode(json_decode($json, false, 512, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            return $json;
        }
    }

    /** @return array<string, mixed>|null */
    private function readPage(int $pageUid): ?array
    {
        if ($pageUid <= 0) {
            return null;
        }
        $page = BackendUtility::readPageAccess($pageUid, $this->backendUser()->getPagePermsClause(Permission::PAGE_SHOW));

        return $page === false ? null : Typed::stringKeyedArray($page);
    }

    /** @param array<string, mixed> $run */
    private function canReadRun(array $run): bool
    {
        $backendUser = $this->backendUser();
        if ($backendUser->isAdmin()) {
            return true;
        }
        // Reports can contain draft content. Editors see reports for their
        // current workspace and only records in their readable page mounts.
        if (Typed::int($run['workspace_uid'] ?? 0) !== $backendUser->workspace) {
            return false;
        }
        $table = Typed::string($run['target_table'] ?? '');
        $recordUid = Typed::int($run['target_uid'] ?? 0);
        if ($table === 'pages') {
            return $this->readPage($recordUid) !== null;
        }
        $record = $recordUid > 0 ? BackendUtility::getRecord($table, $recordUid, 'pid') : null;

        return $record !== null && $this->readPage(Typed::int($record['pid'] ?? 0)) !== null;
    }

    /** @param list<int|string> $arguments */
    private function label(string $key, array $arguments = []): string
    {
        return (string)($this->languageService()->translate($key, self::DOMAIN, $arguments) ?? $key);
    }

    private function backendUser(): BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No backend user available', 1760000050);
        }

        return $backendUser;
    }

    private function languageService(): LanguageService
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            throw new \RuntimeException('No language service available', 1760000051);
        }

        return $languageService;
    }
}
