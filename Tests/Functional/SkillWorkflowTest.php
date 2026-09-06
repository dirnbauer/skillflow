<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Functional;

use Netresearch\NrLlm\Service\LlmServiceManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Skillflow\Domain\SkillRunResult;
use Webconsulting\Skillflow\Event\AfterSkillRunEvent;
use Webconsulting\Skillflow\Hooks\DataHandlerHook;
use Webconsulting\Skillflow\Runner\AnthropicApiRunner;
use Webconsulting\Skillflow\Runner\ClaudeCliRunner;
use Webconsulting\Skillflow\Runner\ContextAwareSkillRunnerInterface;
use Webconsulting\Skillflow\Runner\EngineResolver;
use Webconsulting\Skillflow\Runner\NrLlmRunner;
use Webconsulting\Skillflow\Runner\PromptBuilder;
use Webconsulting\Skillflow\Runner\RunnerFactory;
use Webconsulting\Skillflow\Service\ContentCollector;
use Webconsulting\Skillflow\Service\ContextResolver;
use Webconsulting\Skillflow\Service\EnvironmentGuard;
use Webconsulting\Skillflow\Service\SkillExecutionService;
use Webconsulting\Skillflow\Service\SkillFinder;

final class SkillWorkflowTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'scheduler', 'reports', 'tstemplate', 'install'];
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'apache-solr-for-typo3/solr',
        __DIR__ . '/../..',
    ];
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => ['skillflow' => ['requireLocalEnvironment' => '0', 'defaultEngine' => 'test']],
    ];

    private ConnectionPool $pool;
    private SkillFinder $finder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pool = $this->get(ConnectionPool::class);
        $this->finder = new SkillFinder($this->pool);
        $connection = $this->pool->getConnectionForTable('tx_nrllm_skill');
        foreach ([[], ['enabled' => 0], ['orphaned' => 1], ['hidden' => 1], ['deleted' => 1]] as $index => $flags) {
            $uid = $index + 1;
            $connection->insert('tx_nrllm_skill', $flags + [
                'uid' => $uid,
                'name' => 'Review ' . $uid,
                'identifier' => 'source:review-' . $uid . '/SKILL.md',
                'body' => 'Review the record.',
                'enabled' => 1,
                'allowed_tools' => '["Read","mcp__typo3__review"]',
                'raw_frontmatter' => '{"engine":"test"}',
            ]);
        }
        $this->pool->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'title' => 'Public page']);
        $this->pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'test-admin', 'admin' => 1]);
        $this->setUpBackendUser(1);
    }

    public function testAssignmentsAndPublicLookupOnlyReturnAvailableSkills(): void
    {
        self::assertSame([1], array_column($this->finder->findSkillsByUidList('1,2,3,4,5'), 'uid'));
        self::assertSame([1], array_column($this->finder->findAllSkills(), 'uid'));
        self::assertNotNull($this->finder->findSkillByUid(1, false));
        foreach ([2, 3, 4, 5] as $uid) {
            self::assertNull($this->finder->findSkillByUid($uid, false));
        }
        self::assertNotNull($this->finder->findSkillByUid(2));
        self::assertNull($this->finder->findSkillByUid(5));
    }

    public function testIdentifierLookupNormalizesNrLlmRunnerMetadata(): void
    {
        $skill = $this->finder->findSkillByIdentifier('source:review-1/SKILL.md');

        self::assertNotNull($skill);
        self::assertSame(1, $skill['uid']);
        self::assertSame('Review 1', $skill['title']);
        self::assertSame('Read,mcp__typo3__review', $skill['allowed_tools']);
        self::assertSame(['engine' => 'test'], json_decode($skill['metadata'], true));
        self::assertNull($this->finder->findSkillByIdentifier("missing' OR 1=1 --"));
        self::assertNull($this->finder->findSkillByIdentifier('source:review-5/SKILL.md'));
    }

    #[DataProvider('unavailableSkills')]
    public function testUnavailableSkillIsRecordedAsBlockedWithoutCallingEngine(int $skillUid): void
    {
        $engine = $this->createMock(ContextAwareSkillRunnerInterface::class);
        $engine->method('getIdentifier')->willReturn('test');
        $engine->expects(self::never())->method('runInContext');
        $engine->expects(self::never())->method('canRun');

        $result = $this->executionService($engine)->runSkillOnRecord($skillUid, 'pages', 1, 0);

        self::assertSame('blocked', $result->status);
        self::assertStringContainsString('hidden, disabled, or orphaned', $result->output);
        $runs = $this->finder->findRecentRuns();
        self::assertCount(1, $runs);
        self::assertSame('blocked', $runs[0]['status']);
    }

    public static function unavailableSkills(): iterable
    {
        yield 'disabled' => [2];
        yield 'orphaned' => [3];
        yield 'hidden' => [4];
    }

    public function testContextEngineReceivesPersistedRunBeforeExecution(): void
    {
        $engine = $this->createMock(ContextAwareSkillRunnerInterface::class);
        $engine->method('getIdentifier')->willReturn('test');
        $engine->method('canRun')->willReturn(true);
        $engine->method('wantsCollectedContent')->willReturn(false);
        $engine->expects(self::once())->method('runInContext')->willReturnCallback(function (array $skill, $context): SkillRunResult {
            self::assertSame(1, $skill['uid']);
            self::assertGreaterThan(0, $context->skillRunUid);
            self::assertSame('running', $this->finder->findRunByUid($context->skillRunUid)['status']);
            return new SkillRunResult('pending', 'Queued', 'test');
        });

        $result = $this->executionService($engine)->runSkillOnRecord(1, 'pages', 1, 0);

        self::assertSame('pending', $result->status);
        self::assertSame('pending', $this->finder->findRecentRuns()[0]['status']);
    }

    public function testEnvironmentBlockPrecedesTokenResolutionAndBeforeCallbacks(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['skillflow']['requireLocalEnvironment'] = '1';
        $this->pool->getConnectionForTable('tx_nrllm_skill')->update('tx_nrllm_skill', ['body' => 'Review {title}.'], ['uid' => 1]);
        $engine = $this->createMock(ContextAwareSkillRunnerInterface::class);
        $engine->expects(self::never())->method('canRun');
        $engine->expects(self::never())->method('runInContext');
        $events = [];
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function (object $event) use (&$events): object {
            $events[] = $event;
            return $event;
        });

        $result = $this->executionService($engine, $dispatcher)->runSkillOnRecord(1, 'pages', 1, 0, instructions: 'Check {title}.');

        self::assertSame('blocked', $result->status);
        self::assertStringContainsString('must be Development', $result->output);
        self::assertCount(1, $events);
        self::assertInstanceOf(AfterSkillRunEvent::class, $events[0]);
        self::assertSame('Review {title}.', $events[0]->skill['body']);
        self::assertSame('Check {title}.', $events[0]->context->instructions);
        self::assertSame('blocked', $this->finder->findRecentRuns()[0]['status']);
    }

    public function testNewWorkspaceRecordsAreAutomaticallySentToConfiguredStage(): void
    {
        $this->pool->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'uid' => 1, 'title' => 'Review workspace', 'custom_stages' => 1,
            'tx_skillflow_auto_workflow' => 1, 'tx_skillflow_auto_workflow_stage' => 1,
        ]);
        $this->pool->getConnectionForTable('sys_workspace_stage')->insert('sys_workspace_stage', [
            'uid' => 1, 'parentid' => 1, 'title' => 'Review',
        ]);
        $backendUser = $this->setUpBackendUser(1);
        $backendUser->setWorkspace(1);
        $dataHandler = $this->get(DataHandler::class);
        $dataHandler->start(['tt_content' => [
            'NEWfirst' => ['pid' => 1, 'CType' => 'header', 'header' => 'First record'],
            'NEWsecond' => ['pid' => 1, 'CType' => 'header', 'header' => 'Second record'],
        ]], [], $backendUser);

        $dataHandler->process_datamap();

        self::assertSame([], $dataHandler->errorLog);
        $records = $this->pool->getConnectionForTable('tt_content')->select(['t3ver_wsid', 't3ver_stage'], 'tt_content')->fetchAllAssociative();
        self::assertCount(2, $records);
        foreach ($records as $record) {
            self::assertSame(1, $record['t3ver_wsid']);
            self::assertSame(1, $record['t3ver_stage']);
        }
    }

    public function testRejectedStageCommandDoesNotRunSkills(): void
    {
        $engine = $this->createMock(ContextAwareSkillRunnerInterface::class);
        $engine->method('getIdentifier')->willReturn('test');
        $engine->method('canRun')->willReturn(true);
        $engine->expects(self::never())->method('runInContext');
        $hook = new DataHandlerHook($this->finder, $this->executionService($engine), $this->get(TcaSchemaFactory::class));
        $dataHandler = $this->get(DataHandler::class);
        $this->pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 2, 'title' => 'Workspace page', 't3ver_oid' => 1, 't3ver_wsid' => 1,
        ]);
        $this->pool->getConnectionForTable('sys_workspace_stage')->insert('sys_workspace_stage', [
            'uid' => 1, 'parentid' => 1, 'title' => 'Review', 'tx_skillflow_auto_run' => 1, 'tx_skillflow_nrllm_skills' => '1',
        ]);
        $value = ['action' => 'setStage', 'stageId' => 1];

        $hook->processCmdmap_preProcess('version', 'pages', 2, $value, $dataHandler);
        // Core invokes the post hook even when its permission checks left the record unchanged.
        $dataHandler->errorLog[] = 'Stage change denied';
        $hook->processCmdmap_postProcess('version', 'pages', 2, $value, $dataHandler);

        self::assertSame([], $this->finder->findRecentRuns());
    }

    public function testSuccessfulStageTransitionUsesRecordWorkspace(): void
    {
        $this->pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 2, 'title' => 'Workspace page', 't3ver_oid' => 1, 't3ver_wsid' => 7,
        ]);
        $this->pool->getConnectionForTable('sys_workspace_stage')->insert('sys_workspace_stage', [
            'uid' => 1, 'parentid' => 7, 'title' => 'Review', 'tx_skillflow_auto_run' => 1, 'tx_skillflow_nrllm_skills' => '1',
        ]);
        $engine = $this->createMock(ContextAwareSkillRunnerInterface::class);
        $engine->method('getIdentifier')->willReturn('test');
        $engine->method('canRun')->willReturn(true);
        $engine->method('wantsCollectedContent')->willReturn(false);
        $engine->expects(self::once())->method('runInContext')->willReturnCallback(static function (array $skill, $context): SkillRunResult {
            self::assertSame(7, $context->workspaceId);
            return new SkillRunResult('success', 'Reviewed', 'test');
        });
        $hook = new DataHandlerHook($this->finder, $this->executionService($engine), $this->get(TcaSchemaFactory::class));
        $dataHandler = $this->get(DataHandler::class);
        $value = ['action' => 'setStage', 'stageId' => 1];

        $hook->processCmdmap_preProcess('version', 'pages', 2, $value, $dataHandler);
        $this->pool->getConnectionForTable('pages')->update('pages', ['t3ver_stage' => 1], ['uid' => 2]);
        $hook->processCmdmap_postProcess('version', 'pages', 2, $value, $dataHandler);

        self::assertSame(7, $this->finder->findRecentRuns()[0]['workspace_uid']);
    }

    public function testExplicitEmptyToolDeclarationDisablesCliAndMcpTools(): void
    {
        $binary = $this->getInstancePath() . '/fake-claude';
        file_put_contents($binary, "#!/usr/bin/env php\n<?php echo json_encode(\$argv, JSON_THROW_ON_ERROR);\n");
        chmod($binary, 0700);
        $configuration = $this->createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturn([
            'claudeBinary' => $binary,
            'mcpConfigJson' => '{"mcpServers":{"example":{"command":"unused"}}}',
        ]);
        $runner = new ClaudeCliRunner($configuration, new PromptBuilder());
        try {
            $result = $runner->run(['name' => 'Review', 'allowed_tools' => '', 'allowed_tools_json' => '[]'], 'Content');
        } finally {
            unlink($binary);
        }

        $arguments = json_decode($result->output, true, flags: JSON_THROW_ON_ERROR);
        $toolsIndex = array_search('--tools', $arguments, true);
        self::assertIsInt($toolsIndex);
        self::assertSame('', $arguments[$toolsIndex + 1]);
        self::assertContains('--strict-mcp-config', $arguments);
        self::assertNotContains('--mcp-config', $arguments);
        self::assertNotContains('--allowedTools', $arguments);
    }

    public function testSchemaCollectionKeepsDraftContentAndLiteralLabelTokens(): void
    {
        $this->pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 2, 'title' => 'Draft page', 't3ver_oid' => 1, 't3ver_wsid' => 7,
        ]);
        $connection = $this->pool->getConnectionForTable('tt_content');
        $connection->insert('tt_content', ['uid' => 1, 'pid' => 1, 'header' => 'Live header', 'bodytext' => 'Live body']);
        $connection->insert('tt_content', [
            'uid' => 2, 'pid' => 1, 'header' => 'Draft {table}', 'bodytext' => '<p>Draft body</p>', 't3ver_oid' => 1, 't3ver_wsid' => 7,
        ]);
        $schemaFactory = $this->get(TcaSchemaFactory::class);
        $content = (new ContentCollector($this->pool, $schemaFactory))->collect('pages', 1, 7);

        self::assertStringContainsString('Draft page', $content);
        self::assertStringContainsString('Draft {table}', $content);
        self::assertStringContainsString('Draft body', $content);
        self::assertStringNotContainsString('Live header', $content);
        self::assertStringNotContainsString('Live body', $content);
        self::assertStringNotContainsString('**sorting**:', $content);
        $resolver = new ContextResolver($schemaFactory);
        self::assertSame('Draft {table} (2)', $resolver->apply('{title} ({uid})', $resolver->resolveTokens('tt_content', 2, 7)));
    }

    public function testCompletionListenerFailureDoesNotBreakTheEditingRequest(): void
    {
        $engine = $this->createStub(ContextAwareSkillRunnerInterface::class);
        $engine->method('getIdentifier')->willReturn('test');
        $engine->method('canRun')->willReturn(true);
        $engine->method('wantsCollectedContent')->willReturn(false);
        $engine->method('runInContext')->willReturn(new SkillRunResult('success', 'Reviewed', 'test'));
        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $dispatcher->method('dispatch')->willReturnCallback(static function (object $event): object {
            if ($event instanceof AfterSkillRunEvent) {
                throw new \RuntimeException('Completion notification failed');
            }
            return $event;
        });

        $result = $this->executionService($engine, $dispatcher)->runSkillOnRecord(1, 'pages', 1, 0);

        self::assertSame('success', $result->status);
        self::assertSame('success', $this->finder->findRecentRuns()[0]['status']);
    }

    private function executionService(ContextAwareSkillRunnerInterface $engine, ?EventDispatcherInterface $dispatcher = null): SkillExecutionService
    {
        $configuration = new ExtensionConfiguration();
        $prompt = new PromptBuilder();
        $llm = $this->createMock(LlmServiceManagerInterface::class);
        $llm->expects(self::never())->method('chat');
        $factory = new RunnerFactory(
            $configuration,
            new AnthropicApiRunner($this->get(RequestFactory::class), $configuration, $prompt),
            new ClaudeCliRunner($configuration, $prompt),
            new NrLlmRunner($configuration, $prompt, $llm),
        );
        if ($dispatcher === null) {
            $dispatcher = $this->createStub(EventDispatcherInterface::class);
            $dispatcher->method('dispatch')->willReturnArgument(0);
        }

        return new SkillExecutionService(
            new EnvironmentGuard($configuration),
            new ContentCollector($this->pool, $this->get(TcaSchemaFactory::class)),
            $factory,
            new EngineResolver([$engine], $configuration, new NullLogger()),
            $this->finder,
            new ContextResolver($this->get(TcaSchemaFactory::class)),
            $this->pool,
            $dispatcher,
            new NullLogger(),
        );
    }
}
