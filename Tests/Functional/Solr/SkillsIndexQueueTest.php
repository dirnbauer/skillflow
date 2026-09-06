<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Functional\Solr;

use ApacheSolrForTypo3\Solr\Domain\Index\Queue\QueueInitializationService;
use ApacheSolrForTypo3\Solr\Domain\Site\Site;
use ApacheSolrForTypo3\Solr\IndexQueue\Item;
use ApacheSolrForTypo3\Solr\System\Configuration\TypoScriptConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Skillflow\Solr\SkillsIndexQueue;
use Webconsulting\Skillflow\Solr\SkillsRecordInitializer;

final class SkillsIndexQueueTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'scheduler', 'reports', 'tstemplate', 'install'];
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'apache-solr-for-typo3/solr',
        __DIR__ . '/../../..',
    ];

    private ConnectionPool $pool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pool = $this->get(ConnectionPool::class);
        $pages = $this->pool->getConnectionForTable('pages');
        foreach ([1 => 0, 2 => 0, 3 => 1, 99 => 2, 100 => 2] as $uid => $pid) {
            $pages->insert('pages', ['uid' => $uid, 'pid' => $pid, 'title' => 'Page ' . $uid]);
        }
        $skills = $this->pool->getConnectionForTable('tx_nrllm_skill');
        foreach ([[], ['enabled' => 0], ['orphaned' => 1], ['hidden' => 1], ['deleted' => 1], ['pid' => 3], ['pid' => 99], ['pid' => 100]] as $index => $flags) {
            $uid = $index + 1;
            $skills->insert('tx_nrllm_skill', $flags + [
                'uid' => $uid,
                'pid' => 0,
                'identifier' => 'fixture:skill-' . $uid,
                'name' => 'Skill ' . $uid,
                'enabled' => 1,
                'tstamp' => time(),
            ]);
        }
    }

    #[DataProvider('storageFolders')]
    public function testInitializationIncludesRootAndConfiguredFoldersWithoutChangingOtherQueues(string $additionalPageIds, array $expectedSkills): void
    {
        $queue = $this->pool->getConnectionForTable('tx_solr_indexqueue_item');
        $queue->insert('tx_solr_indexqueue_item', $this->queueRow(1, 'pages', 1, 'pages'));
        $pageQueueUid = (int)$queue->lastInsertId();
        $queue->insert('tx_solr_indexqueue_item', $this->queueRow(2, 'tx_nrllm_skill', 8, 'skills'));
        $otherSiteQueueUid = (int)$queue->lastInsertId();
        $queue->insert('tx_solr_indexqueue_item', $this->queueRow(1, 'tx_nrllm_skill', 2, 'skills'));

        $status = (new QueueInitializationService())->initializeBySiteAndIndexConfiguration($this->createSite($additionalPageIds), 'skills');

        self::assertSame(['skills' => true], $status);
        self::assertSame($expectedSkills, $queue->select(['item_uid'], 'tx_solr_indexqueue_item', ['root' => 1, 'indexing_configuration' => 'skills'], [], ['item_uid' => 'ASC'])->fetchFirstColumn());
        self::assertSame('pages', $queue->select(['indexing_configuration'], 'tx_solr_indexqueue_item', ['uid' => $pageQueueUid])->fetchOne());
        self::assertSame(2, $queue->select(['root'], 'tx_solr_indexqueue_item', ['uid' => $otherSiteQueueUid])->fetchOne());
    }

    public static function storageFolders(): iterable
    {
        yield 'root PID zero' => ['0', [1, 6]];
        yield 'extra storage folder' => ['99', [1, 6, 7]];
    }

    public function testScopedBatchExcludesOtherSitesAndConfigurationsAndHonorsLimit(): void
    {
        $connection = $this->pool->getConnectionForTable('tx_solr_indexqueue_item');
        foreach ([
            $this->queueRow(1, 'pages', 1, 'pages'),
            $this->queueRow(2, 'tx_nrllm_skill', 8, 'skills'),
            $this->queueRow(1, 'tx_nrllm_skill', 1, 'other-skills'),
            $this->queueRow(1, 'tx_nrllm_skill', 1, 'skills'),
            $this->queueRow(1, 'tx_nrllm_skill', 6, 'skills'),
            $this->queueRow(1, 'tx_nrllm_skill', 7, 'skills'),
        ] as $row) {
            $connection->insert('tx_solr_indexqueue_item', $row);
        }

        $queue = new SkillsIndexQueue();
        $site = $this->createSite('0');
        $items = $queue->getItemsToIndex($site, 2);

        self::assertCount(2, $items);
        foreach ($items as $item) {
            self::assertSame(1, $item->getRootPageUid());
            self::assertSame('skills', $item->getIndexingConfigurationName());
            self::assertSame('tx_nrllm_skill', $item->getType());
        }
        self::assertEqualsCanonicalizing([1, 6, 7], array_map(static fn (Item $item): int => $item->getRecordUid(), $queue->getItemsToIndex($site, 10)));
    }

    /** @return array<string, int|string> */
    private function queueRow(int $root, string $type, int $uid, string $configuration): array
    {
        return ['root' => $root, 'item_type' => $type, 'item_uid' => $uid, 'indexing_configuration' => $configuration, 'changed' => time(), 'errors' => ''];
    }

    private function createSite(string $additionalPageIds): Site
    {
        $configuration = new TypoScriptConfiguration([
            'plugin.' => ['tx_solr.' => ['index.' => ['queue.' => [
                'skills' => 1,
                'skills.' => [
                    'type' => 'tx_nrllm_skill',
                    'initialization' => SkillsRecordInitializer::class,
                    'additionalPageIds' => $additionalPageIds,
                    'additionalWhereClause' => 'enabled = 1 AND orphaned = 0',
                ],
            ]]]],
        ]);
        return new Site($configuration, ['uid' => 1, 'pid' => 0, 'title' => 'Main'], 'https://example.test/', 'main');
    }
}
