<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Skillflow\Command\EnableSkillsCommand;
use Webconsulting\Skillflow\Command\ListSkillsCommand;
use Webconsulting\Skillflow\Command\SyncSkillsCommand;
use Webconsulting\Skillflow\Tests\Functional\Fixtures\FakeGitHubClient;

final class SkillCommandsTest extends FunctionalTestCase
{
    private const REVIEW_SKILL = "---\nname: Review\ndescription: Reviews editorial content\n---\nReview the record.\n";
    private const REVIEW_SKILL_CHANGED = "---\nname: Review\ndescription: Reviews editorial content\n---\nReview the record thoroughly.\n";
    private const SEO_SKILL = "---\nname: SEO check\ndescription: Checks metadata\nallowed-tools: Read\n---\nCheck the metadata.\n";

    protected array $coreExtensionsToLoad = ['workspaces', 'scheduler', 'reports', 'tstemplate', 'install'];
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'apache-solr-for-typo3/solr',
        __DIR__ . '/../../..',
        __DIR__ . '/../Fixtures/Extensions/skillflow_test',
    ];

    private ConnectionPool $pool;

    protected function setUp(): void
    {
        parent::setUp();
        FakeGitHubClient::reset();
        $this->pool = $this->get(ConnectionPool::class);
        $sources = $this->pool->getConnectionForTable('tx_nrllm_skill_source');
        $sources->insert('tx_nrllm_skill_source', [
            'uid' => 1, 'title' => 'Review skills', 'type' => 'repo', 'url' => 'https://github.com/example/skills', 'enabled' => 1,
        ]);
        $sources->insert('tx_nrllm_skill_source', [
            'uid' => 2, 'title' => 'Disabled source', 'type' => 'repo', 'url' => 'https://github.com/example/disabled', 'enabled' => 0,
        ]);
        $this->pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1]);
        $this->setUpBackendUser(1);
    }

    protected function tearDown(): void
    {
        FakeGitHubClient::reset();
        parent::tearDown();
    }

    public function testSyncCreatesSkillsDisabledForReview(): void
    {
        FakeGitHubClient::$files = [
            'README.md' => '# Not a skill',
            'skills/review/SKILL.md' => self::REVIEW_SKILL,
            'skills/seo/SKILL.md' => self::SEO_SKILL,
        ];

        $tester = $this->runCommand(SyncSkillsCommand::class, ['source' => '1']);

        $display = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $display);
        self::assertStringContainsString('Review skills (uid 1, repo)', $display);
        self::assertMatchesRegularExpression('/Status\s+ok/', $display);
        self::assertMatchesRegularExpression('/Created\s+2/', $display);
        self::assertSame([
            ['identifier' => '1:skills/review/SKILL.md', 'name' => 'Review', 'source' => 1, 'enabled' => 0, 'orphaned' => 0, 'support_status' => 'full', 'source_sha' => 'fixture-sha'],
            ['identifier' => '1:skills/seo/SKILL.md', 'name' => 'SEO check', 'source' => 1, 'enabled' => 0, 'orphaned' => 0, 'support_status' => 'partial', 'source_sha' => 'fixture-sha'],
        ], $this->skillRows());
        self::assertSame(
            ['sync_status' => 'ok', 'pinned_sha' => 'fixture-sha'],
            $this->pool->getConnectionForTable('tx_nrllm_skill_source')->select(['sync_status', 'pinned_sha'], 'tx_nrllm_skill_source', ['uid' => 1])->fetchAssociative(),
        );
        self::assertSame(2, $this->auditCount('ingest_created'));
    }

    public function testResyncDisablesChangedSkillsAndOrphansRemovedOnes(): void
    {
        FakeGitHubClient::$files = ['skills/review/SKILL.md' => self::REVIEW_SKILL];
        $this->runCommand(SyncSkillsCommand::class, ['--all' => true]);
        $this->runCommand(EnableSkillsCommand::class, ['skills' => ['1:skills/review/SKILL.md']]);
        FakeGitHubClient::$files = ['skills/review/SKILL.md' => self::REVIEW_SKILL_CHANGED, 'skills/seo/SKILL.md' => self::SEO_SKILL];

        $tester = $this->runCommand(SyncSkillsCommand::class, ['--all' => true]);

        $display = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $display);
        self::assertStringContainsString('Review skills', $display);
        self::assertStringNotContainsString('Disabled source', $display);
        self::assertMatchesRegularExpression('/Created\s+1/', $display);
        self::assertMatchesRegularExpression('/Disabled on change\s+1/', $display);
        self::assertSame([0, 0], array_column($this->skillRows(), 'enabled'));

        FakeGitHubClient::$files = ['skills/seo/SKILL.md' => self::SEO_SKILL];
        $tester = $this->runCommand(SyncSkillsCommand::class, ['source' => 'review skills']);

        self::assertMatchesRegularExpression('/Orphaned\s+1/', $tester->getDisplay());
        self::assertSame([1, 0], array_column($this->skillRows(), 'orphaned'));
        self::assertSame(1, $this->auditCount('enabled'));
        self::assertSame(1, $this->auditCount('ingest_disabled_on_change'));
    }

    public function testSyncFailureIsRecordedAndExitsNonZero(): void
    {
        FakeGitHubClient::$failure = new \RuntimeException('GitHub unreachable');

        $tester = $this->runCommand(SyncSkillsCommand::class, ['source' => 'Review skills']);

        $display = $tester->getDisplay();
        self::assertSame(Command::FAILURE, $tester->getStatusCode(), $display);
        self::assertMatchesRegularExpression('/Status\s+error/', $display);
        self::assertStringContainsString('GitHub unreachable', $display);
        self::assertSame(
            ['sync_status' => 'error', 'sync_error' => 'GitHub unreachable'],
            $this->pool->getConnectionForTable('tx_nrllm_skill_source')->select(['sync_status', 'sync_error'], 'tx_nrllm_skill_source', ['uid' => 1])->fetchAssociative(),
        );
        self::assertSame([], $this->skillRows());
    }

    /** @return iterable<string, array{array<string, mixed>, int, string}> */
    public static function unusableSyncArguments(): iterable
    {
        yield 'unknown source' => [['source' => 'Nope'], Command::FAILURE, 'Skill source not found: Nope'];
        yield 'disabled source' => [['source' => 'Disabled source'], Command::FAILURE, 'is disabled'];
        yield 'neither source nor --all' => [[], Command::INVALID, 'exactly one of'];
        yield 'both source and --all' => [['source' => '1', '--all' => true], Command::INVALID, 'exactly one of'];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('unusableSyncArguments')]
    public function testSyncRejectsUnusableArgumentsWithoutTouchingSources(array $input, int $exitCode, string $message): void
    {
        $tester = $this->runCommand(SyncSkillsCommand::class, $input);

        self::assertSame($exitCode, $tester->getStatusCode());
        self::assertStringContainsString($message, $tester->getDisplay());
        $statuses = $this->pool->getConnectionForTable('tx_nrllm_skill_source')->select(['sync_status'], 'tx_nrllm_skill_source')->fetchFirstColumn();
        self::assertSame(['never_synced', 'never_synced'], $statuses);
    }

    public function testEnableMirrorsNrLlmSemantics(): void
    {
        $this->seedSkills();

        $tester = $this->runCommand(EnableSkillsCommand::class, ['skills' => ['1']]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(1, $this->enabledFlag(1));
        self::assertSame(1, $this->auditCount('enabled'));
        self::assertSame(
            ['skill_identifier' => '1:skills/review/SKILL.md', 'actor_uid' => 1],
            $this->pool->getConnectionForTable('tx_nrllm_skill_audit')->select(['skill_identifier', 'actor_uid'], 'tx_nrllm_skill_audit', ['event' => 'enabled'])->fetchAssociative(),
        );

        $tester = $this->runCommand(EnableSkillsCommand::class, ['skills' => ['2']]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('orphaned', $tester->getDisplay());
        self::assertSame(0, $this->enabledFlag(2));

        $tester = $this->runCommand(EnableSkillsCommand::class, ['skills' => ['3']]);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('already enabled', $tester->getDisplay());
        self::assertSame(1, $this->auditCount('enabled'));

        $tester = $this->runCommand(EnableSkillsCommand::class, ['skills' => ['skills/other/SKILL.md'], '--source' => 'Disabled source']);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame(1, $this->enabledFlag(4));

        $tester = $this->runCommand(EnableSkillsCommand::class, ['skills' => ['missing']]);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Skill not found: missing', $tester->getDisplay());
        self::assertSame(2, $this->auditCount('enabled'));
    }

    public function testEnableAllWithinSourceSkipsOrphanedSkills(): void
    {
        $this->seedSkills();

        $tester = $this->runCommand(EnableSkillsCommand::class, ['--all' => true, '--source' => '1']);

        $display = $tester->getDisplay();
        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $display);
        self::assertStringContainsString('1 enabled, 1 already enabled, 1 orphaned (skipped), 0 not found.', $display);
        $enabled = array_column($this->skillRows(['uid', 'enabled']), 'enabled', 'uid');
        ksort($enabled);
        self::assertSame([1 => 1, 2 => 0, 3 => 1, 4 => 0], $enabled);
        self::assertSame(1, $this->auditCount('enabled'));
    }

    public function testListFiltersBySourceAndEnabledState(): void
    {
        $this->seedSkills();

        $display = $this->runCommand(ListSkillsCommand::class, [])->getDisplay();
        foreach (['1:skills/review/SKILL.md', '1:skills/old/SKILL.md', '1:skills/seo/SKILL.md', '2:skills/other/SKILL.md'] as $identifier) {
            self::assertStringContainsString($identifier, $display);
        }
        self::assertStringContainsString('Review skills (1)', $display);
        self::assertStringContainsString('Disabled source (2)', $display);
        self::assertStringContainsString('partial', $display);
        self::assertStringContainsString('4 skill(s), 1 enabled.', $display);

        $display = $this->runCommand(ListSkillsCommand::class, ['--enabled' => true])->getDisplay();
        self::assertStringContainsString('1:skills/seo/SKILL.md', $display);
        self::assertStringNotContainsString('1:skills/review/SKILL.md', $display);
        self::assertStringContainsString('1 skill(s), 1 enabled.', $display);

        $display = $this->runCommand(ListSkillsCommand::class, ['--source' => 'Disabled source'])->getDisplay();
        self::assertStringContainsString('2:skills/other/SKILL.md', $display);
        self::assertStringNotContainsString('1:skills/review/SKILL.md', $display);

        $tester = $this->runCommand(ListSkillsCommand::class, ['--source' => 'Nope']);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    private function seedSkills(): void
    {
        $connection = $this->pool->getConnectionForTable('tx_nrllm_skill');
        $rows = [
            1 => ['source' => 1, 'identifier' => '1:skills/review/SKILL.md', 'name' => 'Review'],
            2 => ['source' => 1, 'identifier' => '1:skills/old/SKILL.md', 'name' => 'Old', 'orphaned' => 1],
            3 => ['source' => 1, 'identifier' => '1:skills/seo/SKILL.md', 'name' => 'SEO check', 'enabled' => 1, 'support_status' => 'partial'],
            4 => ['source' => 2, 'identifier' => '2:skills/other/SKILL.md', 'name' => 'Other'],
        ];
        foreach ($rows as $uid => $row) {
            $connection->insert('tx_nrllm_skill', $row + ['uid' => $uid, 'body' => 'Body', 'enabled' => 0, 'orphaned' => 0]);
        }
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

    /**
     * @param list<string> $columns
     * @return list<array<string, mixed>>
     */
    private function skillRows(array $columns = ['identifier', 'name', 'source', 'enabled', 'orphaned', 'support_status', 'source_sha']): array
    {
        return $this->pool->getConnectionForTable('tx_nrllm_skill')
            ->select($columns, 'tx_nrllm_skill', [], [], ['identifier' => 'ASC'])
            ->fetchAllAssociative();
    }

    private function enabledFlag(int $uid): int
    {
        return (int)$this->pool->getConnectionForTable('tx_nrllm_skill')->select(['enabled'], 'tx_nrllm_skill', ['uid' => $uid])->fetchOne();
    }

    private function auditCount(string $event): int
    {
        return $this->pool->getConnectionForTable('tx_nrllm_skill_audit')->count('*', 'tx_nrllm_skill_audit', ['event' => $event]);
    }
}
