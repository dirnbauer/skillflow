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

        self::assertStringContainsString('Access denied', (string)$response->getBody());
        self::assertSame(0, $this->getConnectionPool()->getConnectionForTable('tx_skillflow_run')->count('*', 'tx_skillflow_run', []));
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

    private function login(int $uid): void
    {
        $user = $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($user);
    }

    private function request(): ServerRequestInterface
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/content/skillflow', 'GET', null, [], [
            'HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on', 'SERVER_PORT' => 443,
            'SCRIPT_NAME' => '/typo3/index.php', 'SCRIPT_FILENAME' => $this->instancePath . '/typo3/index.php',
            'DOCUMENT_ROOT' => $this->instancePath, 'REQUEST_URI' => '/typo3/module/content/skillflow',
        ]))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('backend.user', $GLOBALS['BE_USER'])
            ->withAttribute('module', $this->get(ModuleProvider::class)->getModule('content_skillflow'))
            ->withAttribute('route', $this->get(Router::class)->getRoute('content_skillflow'));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        return $request;
    }

    private function insertRun(string $table, int $targetUid, int $workspaceUid): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_skillflow_run')->insert('tx_skillflow_run', [
            'uid' => 1, 'skill' => 1, 'target_table' => $table, 'target_uid' => $targetUid,
            'workspace_uid' => $workspaceUid, 'status' => 'success', 'runner' => 'test',
            'output' => 'PRIVATE_RUN_OUTPUT',
        ]);
    }

    private function detailController(): SkillDetailController
    {
        $controller = new SkillDetailController($this->get(SkillFinder::class));
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
        (new \ReflectionProperty($controller, 'view'))->setValue($controller, $view);
        return $controller;
    }
}
