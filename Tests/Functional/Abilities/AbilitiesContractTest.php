<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Functional\Abilities;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Skillflow\Command\CheckSkillsCommand;
use Webconsulting\Skillflow\Command\SyncSkillsCommand;
use Webconsulting\Skillflow\Configuration\ExtensionSettings;
use Webconsulting\Skillflow\Runner\ClaudeCliRunner;
use Webconsulting\Skillflow\Runner\PromptBuilder;
use Webconsulting\Skillflow\Service\SkillAbilityResolver;
use Webconsulting\Skillflow\Service\SkillAbilityStore;
use Webconsulting\Skillflow\Service\SkillFinder;
use Webconsulting\Skillflow\Tests\Functional\Fixtures\FakeGitHubClient;

/**
 * The abilities contract with typo3-abilities active: SKILL.md "abilities:"
 * is stored on sync, becomes --allowedTools of the Claude CLI runner and is
 * checked by skillflow:skills:check. The registry ships system/site-info
 * (scope system:read); news/list is not registered here.
 */
final class AbilitiesContractTest extends FunctionalTestCase
{
    private const string REPORT_SKILL = "---\nname: Site report\ndescription: Reports on the sites\nabilities: [system/site-info, news/list]\n---\nReport.\n";
    private const string LOCKED_SKILL = "---\nname: Locked report\ndescription: No built-in tools\nallowed-tools: []\nabilities: system/site-info\n---\nReport.\n";
    private const string PLAIN_SKILL = "---\nname: Plain\ndescription: Declares nothing\n---\nReview.\n";

    protected array $coreExtensionsToLoad = ['workspaces', 'scheduler', 'reports', 'tstemplate', 'install'];
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'apache-solr-for-typo3/solr',
        'webconsulting/typo3-abilities',
        __DIR__ . '/../../..',
        __DIR__ . '/../Fixtures/Extensions/skillflow_test',
    ];

    private ConnectionPool $pool;

    protected function setUp(): void
    {
        parent::setUp();
        FakeGitHubClient::reset();
        FakeGitHubClient::$files = [
            'skills/report/SKILL.md' => self::REPORT_SKILL,
            'skills/locked/SKILL.md' => self::LOCKED_SKILL,
            'skills/plain/SKILL.md' => self::PLAIN_SKILL,
        ];
        $this->pool = $this->get(ConnectionPool::class);
        $this->pool->getConnectionForTable('tx_nrllm_skill_source')->insert('tx_nrllm_skill_source', [
            'uid' => 1, 'title' => 'Report skills', 'type' => 'repo', 'url' => 'https://github.com/example/skills', 'enabled' => 1,
        ]);
        $users = $this->pool->getConnectionForTable('be_users');
        $users->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1]);
        $users->insert('be_users', ['uid' => 2, 'username' => 'editor', 'admin' => 0]);
        $this->setUpBackendUser(1);
    }

    protected function tearDown(): void
    {
        FakeGitHubClient::reset();
        $policyFile = Environment::getProjectPath() . '/config/abilities-policy.yaml';
        if (is_file($policyFile)) {
            unlink($policyFile);
        }
        parent::tearDown();
    }

    public function testSyncStoresTheDeclaredAbilities(): void
    {
        $tester = $this->runCommand(SyncSkillsCommand::class, ['source' => '1']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertMatchesRegularExpression('/Declaring abilities\s+2/', $tester->getDisplay());
        self::assertSame([
            '1:skills/locked/SKILL.md' => '["system/site-info"]',
            '1:skills/plain/SKILL.md' => '[]',
            '1:skills/report/SKILL.md' => '["system/site-info","news/list"]',
        ], $this->storedAbilities());
        self::assertSame(['system/site-info', 'news/list'], $this->skill('report')['abilities']);
    }

    public function testClaudeCliRunnerAllowsTheRegisteredAbilities(): void
    {
        $this->runCommand(SyncSkillsCommand::class, ['source' => '1']);
        $resolver = $this->get(SkillAbilityResolver::class);
        self::assertTrue($resolver->isAvailable(), 'The registry is autowired when typo3-abilities is active');
        $runner = new ClaudeCliRunner(new ExtensionSettings(), new PromptBuilder(), $resolver);

        $command = $runner->buildCommand('claude', $this->skill('report'), '/tmp/mcp.json');
        self::assertSame('mcp__typo3__ability_system_site-info', $this->option($command, '--allowedTools'));
        self::assertSame('/tmp/mcp.json', $this->option($command, '--mcp-config'));
        self::assertNotContains('--tools', $command);

        // allowed-tools: [] switches the built-in tools off, the abilities stay.
        $locked = $runner->buildCommand('claude', $this->skill('locked'), '/tmp/mcp.json');
        self::assertSame('', $this->option($locked, '--tools'));
        self::assertSame('mcp__typo3__ability_system_site-info', $this->option($locked, '--allowedTools'));
        self::assertContains('--strict-mcp-config', $locked);

        self::assertNotContains('--allowedTools', $runner->buildCommand('claude', $this->skill('plain'), ''));
    }

    public function testCheckReportsAbilitiesThatAreNotRegistered(): void
    {
        $this->runCommand(SyncSkillsCommand::class, ['source' => '1']);

        $tester = $this->runCommand(CheckSkillsCommand::class, ['--json' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertSame(2, $report['checked']);
        self::assertSame(
            [['skill' => 'Site report', 'ability' => 'news/list', 'code' => 'ability_missing']],
            array_map(static fn(array $finding): array => array_intersect_key($finding, ['skill' => 1, 'ability' => 1, 'code' => 1]), $report['findings']),
        );
    }

    public function testCheckReportsAbilitiesTheSitePolicyDenies(): void
    {
        $this->runCommand(SyncSkillsCommand::class, ['source' => '1']);
        GeneralUtility::mkdir_deep(Environment::getProjectPath() . '/config');
        self::assertIsInt(file_put_contents(Environment::getProjectPath() . '/config/abilities-policy.yaml', "policy:\n  name: lab\n  deny:\n    - 'system/*'\n"));

        $tester = $this->runCommand(CheckSkillsCommand::class, []);

        $display = $tester->getDisplay();
        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $display);
        self::assertStringContainsString('ability_denied', $display);
        self::assertStringContainsString('ability_missing', $display);
        self::assertMatchesRegularExpression('/3 finding\(s\) in 2 skill\(s\): 1 ability_missing, 2 ability_denied/', $display);
    }

    public function testCheckAsAUserWithoutTheScopeReportsTheAbilityAsDenied(): void
    {
        $this->runCommand(SyncSkillsCommand::class, ['source' => '1']);

        $report = json_decode(
            $this->runCommand(CheckSkillsCommand::class, ['--as-user' => 'editor', '--json' => true])->getDisplay(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($report);
        $denied = array_values(array_filter($report['findings'], static fn(array $finding): bool => $finding['code'] === 'ability_denied'));
        self::assertCount(2, $denied);
        self::assertStringContainsString('system:read', $denied[0]['message']);
        self::assertStringContainsString('"editor"', $denied[0]['message']);

        $unknown = $this->runCommand(CheckSkillsCommand::class, ['--as-user' => 'nobody']);
        self::assertSame(Command::INVALID, $unknown->getStatusCode());
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(string $commandClass, array $input): CommandTester
    {
        $command = $this->get($commandClass);
        self::assertInstanceOf(Command::class, $command);
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }

    /** @return array<string, mixed> */
    private function skill(string $directory): array
    {
        $skill = $this->get(SkillFinder::class)->findSkillByIdentifier('1:skills/' . $directory . '/SKILL.md');
        self::assertIsArray($skill);

        return $skill;
    }

    /** @return array<string, string> */
    private function storedAbilities(): array
    {
        $rows = $this->pool->getConnectionForTable('tx_nrllm_skill')
            ->select(['identifier', SkillAbilityStore::FIELD], 'tx_nrllm_skill', [], [], ['identifier' => 'ASC'])
            ->fetchAllAssociative();

        return array_column($rows, SkillAbilityStore::FIELD, 'identifier');
    }

    /** @param list<string> $command */
    private function option(array $command, string $option): ?string
    {
        $index = array_search($option, $command, true);

        return is_int($index) ? ($command[$index + 1] ?? null) : null;
    }
}
