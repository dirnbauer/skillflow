<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Functional\Abilities;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Skillflow\Command\CheckSkillsCommand;
use Webconsulting\Skillflow\Command\SyncSkillsCommand;
use Webconsulting\Skillflow\Configuration\ExtensionSettings;
use Webconsulting\Skillflow\Runner\ClaudeCliRunner;
use Webconsulting\Skillflow\Runner\PromptBuilder;
use Webconsulting\Skillflow\Service\SkillAbilityResolver;
use Webconsulting\Skillflow\Service\SkillFinder;
use Webconsulting\Skillflow\Tests\Functional\Fixtures\FakeGitHubClient;

/**
 * typo3-abilities is optional: installed (it is a dev dependency) but not
 * active here, which is the case a class_exists() guard gets wrong. The
 * container must still compile, skills still sync, and every declared
 * ability is reported as missing.
 */
final class WithoutAbilitiesTest extends FunctionalTestCase
{
    private const string REPORT_SKILL = "---\nname: Site report\ndescription: Reports on the sites\nallowed-tools: Read\nabilities: [system/site-info]\n---\nReport.\n";

    protected array $coreExtensionsToLoad = ['workspaces', 'scheduler', 'reports', 'tstemplate', 'install'];
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'apache-solr-for-typo3/solr',
        __DIR__ . '/../../..',
        __DIR__ . '/../Fixtures/Extensions/skillflow_test',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        FakeGitHubClient::reset();
        FakeGitHubClient::$files = ['skills/report/SKILL.md' => self::REPORT_SKILL];
        $pool = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable('tx_nrllm_skill_source')->insert('tx_nrllm_skill_source', [
            'uid' => 1, 'title' => 'Report skills', 'type' => 'repo', 'url' => 'https://github.com/example/skills', 'enabled' => 1,
        ]);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1]);
        $this->setUpBackendUser(1);
    }

    protected function tearDown(): void
    {
        FakeGitHubClient::reset();
        parent::tearDown();
    }

    public function testSkillsWithAbilitiesSyncAndRunWithoutTheRegistry(): void
    {
        $sync = $this->runCommand(SyncSkillsCommand::class, ['source' => '1']);
        self::assertSame(Command::SUCCESS, $sync->getStatusCode(), $sync->getDisplay());

        $resolver = $this->get(SkillAbilityResolver::class);
        self::assertFalse($resolver->isAvailable(), 'An inactive typo3-abilities is autowired as null');
        $skill = $this->get(SkillFinder::class)->findSkillByIdentifier('1:skills/report/SKILL.md');
        self::assertIsArray($skill);
        self::assertSame(['system/site-info'], $skill['abilities']);

        $command = new ClaudeCliRunner(new ExtensionSettings(), new PromptBuilder(), $resolver)->buildCommand('claude', $skill, '');
        $index = array_search('--allowedTools', $command, true);
        self::assertIsInt($index);
        self::assertSame('Read', $command[$index + 1], 'Only the declared allowed-tools remain');
    }

    public function testCheckReportsEveryDeclaredAbilityAsMissing(): void
    {
        $this->runCommand(SyncSkillsCommand::class, ['source' => '1']);

        $tester = $this->runCommand(CheckSkillsCommand::class, []);

        $display = $tester->getDisplay();
        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $display);
        self::assertStringContainsString('typo3-abilities is not active', $display);
        self::assertStringContainsString('1 finding(s) in 1 skill(s): 1 ability_missing, 0 ability_denied', $display);
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
}
