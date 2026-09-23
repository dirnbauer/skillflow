<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\MetaTag\MetaTagManagerRegistry;
use TYPO3\CMS\Core\PageTitle\RecordTitleProvider;
use TYPO3\CMS\Core\View\ViewInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Skillflow\Controller\SkillDetailController;
use Webconsulting\Skillflow\Controller\SkillsModuleController;
use Webconsulting\Skillflow\Service\SkillFinder;

final class ControllerAccessTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'scheduler', 'reports', 'tstemplate', 'install'];
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'apache-solr-for-typo3/solr',
        'webconsulting/skillflow',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->getConnectionPool()->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 1, 'title' => 'Editors', 'db_mountpoints' => '1',
            'groupMods' => 'content_skillflow', 'workspace_perms' => 1,
        ]);
        $users = $this->getConnectionPool()->getConnectionForTable('be_users');
        $users->insert('be_users', ['uid' => 1, 'username' => 'editor', 'usergroup' => '1']);
        $users->insert('be_users', ['uid' => 2, 'username' => 'admin', 'admin' => 1]);
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'title' => 'Mounted root', 'perms_everybody' => 1]);
        $pages->insert('pages', ['uid' => 2, 'pid' => 1, 'title' => 'Readable page', 'perms_everybody' => 1]);
        $pages->insert('pages', ['uid' => 3, 'title' => 'Outside mount', 'perms_everybody' => 1]);
        $this->getConnectionPool()->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 1, 'pid' => 2, 'CType' => 'text', 'header' => 'Readable content',
        ]);
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill')->insert('tx_nrllm_skill', [
            'uid' => 1, 'name' => 'Test skill', 'identifier' => 'test-skill', 'enabled' => 1,
            'body' => 'PRIVATE_SKILL_BODY', 'allowed_tools' => '[]',
        ]);
        $this->login(1);
    }

    /** @return iterable<string, array{string}> */
    public static function runActions(): iterable
    {
        yield 'single skill' => ['run'];
        yield 'assigned skills' => ['runPageSkills'];
    }

    #[DataProvider('runActions')]
    public function testRunCannotReadPageOutsideUserMount(string $action): void
    {
        $request = $this->request()->withMethod('POST')->withParsedBody([
            'action' => $action, 'skill' => 1, 'page' => 3,
        ]);
        $response = $this->get(SkillsModuleController::class)->handleRequest($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertContains('Access denied', $this->flashMessageTitles());
        self::assertSame(0, $this->getConnectionPool()->getConnectionForTable('tx_skillflow_run')->count('*', 'tx_skillflow_run', []));
    }

    public function testSingleRunRedirectsToItsReport(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['skillflow']['requireLocalEnvironment'] = '0';
        // An unset key variable keeps the classic chain from reaching a real provider.
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['skillflow']['runner'] = 'anthropic';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['skillflow']['apiKeyEnvVar'] = 'SKILLFLOW_TEST_UNSET_KEY';
        $request = $this->request()->withMethod('POST')->withParsedBody([
            'action' => 'run', 'skill' => 1, 'page' => 2,
        ]);
        $response = $this->get(SkillsModuleController::class)->handleRequest($request);

        $run = $this->getConnectionPool()->getConnectionForTable('tx_skillflow_run')->select(['uid', 'status'], 'tx_skillflow_run')->fetchAssociative();
        self::assertIsArray($run);
        self::assertSame('blocked', $run['status']);
        self::assertSame(303, $response->getStatusCode());
        parse_str((string)parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $parameters);
        self::assertSame(['showRun', (string)$run['uid']], [$parameters['action'] ?? null, $parameters['run'] ?? null]);
        self::assertContains('Test skill: Blocked', $this->flashMessageTitles());
    }

    public function testIndexListsReadableRunsWithStatus(): void
    {
        $this->login(2);
        $this->insertRun('pages', 2, 0);
        $body = (string)$this->get(SkillsModuleController::class)->handleRequest(
            $this->request()->withQueryParams(['id' => 2]),
        )->getBody();

        self::assertStringContainsString('<h1>Skills</h1>', $body);
        self::assertStringContainsString('badge badge-success', $body);
        self::assertStringContainsString('Readable page', $body);
        self::assertStringContainsString('action=showRun', html_entity_decode($body));
    }

    /**
     * The Skill column names the skill and links its nr_llm record; a run
     * whose skill is gone says so instead of showing a bare "#uid".
     */
    public function testReportsNameExistingAndDeletedSkills(): void
    {
        $this->login(2);
        $this->insertRun('pages', 2, 0);
        $this->insertRunsOfDeletedSkills();

        $body = html_entity_decode((string)$this->get(SkillsModuleController::class)->handleRequest(
            $this->request()->withQueryParams(['id' => 2]),
        )->getBody());

        self::assertMatchesRegularExpression('#<a href="[^"]*record/edit[^"]*edit%5Btx_nrllm_skill%5D%5B1%5D=edit[^"]*"[^>]*>Test skill</a>#', $body);
        self::assertSame(2, substr_count($body, 'Deleted skill'));
        self::assertStringContainsString('Old review (3:skills/old-review/SKILL.md)', $body);
        self::assertStringNotContainsString('#119', $body);
        self::assertStringNotContainsString('#0', $body);
    }

    public function testReportOfADeletedSkillSaysSo(): void
    {
        $this->login(2);
        $this->insertRunsOfDeletedSkills();

        $report = (string)$this->get(SkillsModuleController::class)->handleRequest(
            $this->request()->withQueryParams(['action' => 'showRun', 'run' => 2]),
        )->getBody();

        self::assertMatchesRegularExpression('#<h1>[^<]*: Deleted skill</h1>#', $report);
        self::assertStringContainsString('Old review (3:skills/old-review/SKILL.md)', $report);
    }

    public function testRunRecordsTheSkillNameAndIdentifier(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['skillflow']['requireLocalEnvironment'] = '0';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['skillflow']['runner'] = 'anthropic';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['skillflow']['apiKeyEnvVar'] = 'SKILLFLOW_TEST_UNSET_KEY';
        $this->get(SkillsModuleController::class)->handleRequest(
            $this->request()->withMethod('POST')->withParsedBody(['action' => 'run', 'skill' => 1, 'page' => 2]),
        );

        self::assertSame(
            ['skill_name' => 'Test skill', 'skill_identifier' => 'test-skill'],
            $this->getConnectionPool()->getConnectionForTable('tx_skillflow_run')
                ->select(['skill_name', 'skill_identifier'], 'tx_skillflow_run')->fetchAssociative(),
        );
    }

    /**
     * Without typo3-abilities (not loaded in this test) the module shows
     * which declared abilities cannot be resolved.
     */
    public function testRunFormListsDeclaredAbilitiesWithTheirStatus(): void
    {
        $this->login(2);
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill')->update('tx_nrllm_skill', [
            'raw_frontmatter' => json_encode(['abilities' => ['news/list']], JSON_THROW_ON_ERROR),
        ], ['uid' => 1]);

        $body = (string)$this->get(SkillsModuleController::class)->handleRequest(
            $this->request()->withQueryParams(['id' => 2]),
        )->getBody();

        self::assertStringContainsString('Abilities of the skills', $body);
        self::assertStringContainsString('<code>news/list</code>', $body);
        self::assertStringContainsString('ability_missing', $body);
        self::assertStringContainsString('Abilities registry not active', $body);
    }

    public function testReportIsRenderedAsSafeMarkdown(): void
    {
        $this->login(2);
        $this->insertRun('pages', 2, 0, "## Findings\n\n<script>alert(1)</script>\n\n[x](javascript:alert(1))\n\n> Quoted \"note\"\n\n```\ngenerate-test.sh <Type>\n```");
        $body = (string)$this->get(SkillsModuleController::class)->handleRequest(
            $this->request()->withQueryParams(['action' => 'showRun', 'run' => 1]),
        )->getBody();

        self::assertStringContainsString('<h2>Findings</h2>', $body);
        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringNotContainsString('href="javascript:', $body);
        // The Markdown reaches the converter verbatim: one level of escaping, real quotes and blocks.
        self::assertMatchesRegularExpression('#<blockquote>\s*<p>Quoted (&quot;|")note(&quot;|")</p>\s*</blockquote>#', $body);
        self::assertStringContainsString('generate-test.sh &lt;Type&gt;', $body);
        self::assertStringNotContainsString('&amp;lt;', $body);
    }

    /** @return iterable<string, array{int, int}> */
    public static function inaccessibleReports(): iterable
    {
        yield 'outside page mount' => [3, 0];
        yield 'other workspace draft' => [2, 1];
    }

    #[DataProvider('inaccessibleReports')]
    public function testEditorCannotRetrieveInaccessibleReport(int $pageUid, int $workspaceUid): void
    {
        $this->insertRun('pages', $pageUid, $workspaceUid);
        $response = $this->get(SkillsModuleController::class)->handleRequest(
            $this->request()->withQueryParams(['action' => 'showRun', 'run' => 1]),
        );

        self::assertStringNotContainsString('PRIVATE_RUN_OUTPUT', (string)$response->getBody());
        self::assertStringContainsString('not found', (string)$response->getBody());
    }

    public function testEditorCanReadReportForContentInMountedPage(): void
    {
        $this->insertRun('tt_content', 1, 0);
        $response = $this->get(SkillsModuleController::class)->handleRequest(
            $this->request()->withQueryParams(['action' => 'showRun', 'run' => 1]),
        );

        self::assertStringContainsString('PRIVATE_RUN_OUTPUT', (string)$response->getBody());
    }

    public function testAdminCanReadReportOutsideEditorMount(): void
    {
        $this->login(2);
        $this->insertRun('pages', 3, 1);
        $response = $this->get(SkillsModuleController::class)->handleRequest(
            $this->request()->withQueryParams(['action' => 'showRun', 'run' => 1]),
        );

        self::assertStringContainsString('PRIVATE_RUN_OUTPUT', (string)$response->getBody());
    }

    public function testRunFormContainsValidBackendRouteToken(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['skillflow']['requireLocalEnvironment'] = 0;
        $request = $this->request()->withQueryParams(['id' => 2]);
        $response = $this->get(SkillsModuleController::class)->handleRequest($request);
        self::assertSame(1, preg_match('/<form[^>]*action="([^"]+)"/', (string)$response->getBody(), $matches));
        parse_str((string)parse_url(html_entity_decode($matches[1]), PHP_URL_QUERY), $parameters);

        self::assertIsString($parameters['token'] ?? null);
        self::assertTrue($this->get(FormProtectionFactory::class)->createFromRequest($request)
            ->validateToken($parameters['token'], 'route', 'content_skillflow'));
    }

    /** @return iterable<string, array{array<string, int>}> */
    public static function unavailableSkills(): iterable
    {
        yield 'hidden' => [['hidden' => 1]];
        yield 'disabled' => [['enabled' => 0]];
        yield 'orphaned' => [['orphaned' => 1]];
        yield 'deleted' => [['deleted' => 1]];
    }

    /** @param array<string, int> $flags */
    #[DataProvider('unavailableSkills')]
    public function testPublicDetailDoesNotExposeUnavailableSkill(array $flags): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill')->update('tx_nrllm_skill', $flags, ['uid' => 1]);
        $response = $this->detailController()->showAction(1);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('PRIVATE_SKILL_BODY', (string)$response->getBody());
    }

    public function testPublicDetailReturnsActiveSkillAndMissingUidIs404(): void
    {
        $response = $this->detailController()->showAction(1);
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('PRIVATE_SKILL_BODY', (string)$response->getBody());
        self::assertSame(404, $this->detailController()->showAction(0)->getStatusCode());
    }

    public function testCatalogueOverridesMatchDetailMetadata(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill')->update('tx_nrllm_skill', [
            'raw_frontmatter' => json_encode(['metadata' => ['category' => 'Old category', 'tags' => ['Old tag']]], JSON_THROW_ON_ERROR),
            'tx_skillflow_search_category' => ' TYPO3 ', 'tx_skillflow_search_tags' => ' Content, Search, ,0 ',
        ], ['uid' => 1]);
        $data = json_decode((string)$this->detailController()->showAction(1)->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('TYPO3', $data['meta']['category']);
        self::assertSame(['Content', 'Search', '0'], $data['meta']['tags']);
    }

    public function testDetailListsTheDeclaredAbilities(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_nrllm_skill')->update('tx_nrllm_skill', [
            'raw_frontmatter' => json_encode(['abilities' => ['news/list', 'solr/index-queue']], JSON_THROW_ON_ERROR),
        ], ['uid' => 1]);
        $data = json_decode((string)$this->detailController()->showAction(1)->getBody(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['news/list', 'solr/index-queue'], $data['abilities']);
    }

    private function login(int $uid): void
    {
        $user = $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
    }

    private function request(): ServerRequestInterface
    {
        $request = new ServerRequest('https://typo3-testing.local/typo3/module/content/skillflow', 'GET', null, [], [
            'HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on', 'SERVER_PORT' => 443,
            'SCRIPT_NAME' => '/typo3/index.php', 'SCRIPT_FILENAME' => $this->instancePath . '/typo3/index.php',
            'DOCUMENT_ROOT' => $this->instancePath, 'REQUEST_URI' => '/typo3/module/content/skillflow',
        ])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('backend.user', $GLOBALS['BE_USER'])
            ->withAttribute('module', $this->get(ModuleProvider::class)->getModule('content_skillflow'))
            ->withAttribute('route', $this->get(Router::class)->getRoute('content_skillflow'));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        return $request;
    }

    private function insertRun(string $table, int $targetUid, int $workspaceUid, string $output = 'PRIVATE_RUN_OUTPUT'): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_skillflow_run')->insert('tx_skillflow_run', [
            'uid' => 1, 'crdate' => 1_790_000_000, 'skill' => 1, 'target_table' => $table, 'target_uid' => $targetUid,
            'workspace_uid' => $workspaceUid, 'status' => 'success', 'runner' => 'test',
            'output' => $output,
        ]);
    }

    /** Run 2: a deleted skill with the name recorded since 1.8.0; run 3: an old run without any. */
    private function insertRunsOfDeletedSkills(): void
    {
        $runs = $this->getConnectionPool()->getConnectionForTable('tx_skillflow_run');
        $runs->insert('tx_skillflow_run', [
            'uid' => 2, 'crdate' => 1_790_000_100, 'skill' => 119, 'skill_name' => 'Old review',
            'skill_identifier' => '3:skills/old-review/SKILL.md', 'target_table' => 'pages', 'target_uid' => 2,
            'status' => 'success', 'runner' => 'test',
        ]);
        $runs->insert('tx_skillflow_run', [
            'uid' => 3, 'crdate' => 1_790_000_200, 'skill' => 0, 'target_table' => 'pages', 'target_uid' => 2,
            'status' => 'blocked', 'runner' => 'none',
        ]);
    }

    /** @return list<string> */
    private function flashMessageTitles(): array
    {
        return array_values(array_map(
            static fn(FlashMessage $message): string => $message->getTitle(),
            $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessages(),
        ));
    }

    private function detailController(): SkillDetailController
    {
        $controller = new SkillDetailController($this->get(SkillFinder::class), new RecordTitleProvider(), $this->get(MetaTagManagerRegistry::class));
        $controller->injectResponseFactory(new ResponseFactory());
        $controller->injectStreamFactory(new StreamFactory());
        $view = new class implements ViewInterface {
            /** @var array<string, mixed> */
            private array $values = [];

            public function assign(string $key, mixed $value): self
            {
                $this->values[$key] = $value;
                return $this;
            }

            public function assignMultiple(array $values): self
            {
                $this->values = $values + $this->values;
                return $this;
            }

            public function render(string $templateFileName = ''): string
            {
                return json_encode($this->values, JSON_THROW_ON_ERROR);
            }
        };
        new \ReflectionProperty($controller, 'view')->setValue($controller, $view);
        return $controller;
    }
}
