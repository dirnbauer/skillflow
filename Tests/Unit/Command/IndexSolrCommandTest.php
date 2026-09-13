<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Unit\Command;

use ApacheSolrForTypo3\Solr\Domain\Index\Queue\QueueInitializationService;
use ApacheSolrForTypo3\Solr\Domain\Site\Site;
use ApacheSolrForTypo3\Solr\Domain\Site\SiteRepository;
use ApacheSolrForTypo3\Solr\System\Configuration\TypoScriptConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Site\Entity\Site as CoreSite;
use Webconsulting\Skillflow\Command\IndexSolrCommand;
use Webconsulting\Skillflow\Solr\SkillsIndexQueue;

/**
 * A site can be Solr-enabled for its own content without carrying the
 * webconsulting/skillflow-solr set. The command used to throw for those, so in
 * any installation where only some sites index skills it reported errors and
 * exited 1 even when every skills-indexing site had finished cleanly.
 *
 * These cover the decisions taken before any indexing happens: which sites are
 * skipped, and the two cases that still fail deliberately. The indexing path
 * itself needs a Solr connection and is exercised by the functional suite.
 */
final class IndexSolrCommandTest extends TestCase
{
    #[Test]
    public function sitesWithoutTheSkillsQueueAreSkippedAndNamed(): void
    {
        $tester = new CommandTester($this->commandFor([
            'content-only' => false,
            'also-content-only' => false,
        ]));

        $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertStringContainsString('Skipped 2 site(s)', $display);
        self::assertStringContainsString('content-only, also-content-only', $display);
    }

    #[Test]
    public function aRunInWhichNoSiteIndexesSkillsFails(): void
    {
        $tester = new CommandTester($this->commandFor(['content-only' => false]));

        $exitCode = $tester->execute([]);

        self::assertStringContainsString('No site indexes skills', $tester->getDisplay());
        self::assertSame(Command::FAILURE, $exitCode);
    }

    #[Test]
    public function anExplicitlyRequestedSiteWithoutTheQueueFails(): void
    {
        $tester = new CommandTester($this->commandFor([
            'content-only' => false,
            'indexes-skills' => true,
        ]));

        $exitCode = $tester->execute(['--site' => 'content-only']);
        $display = $tester->getDisplay();

        // Asking for one site by name is a question about that site, so the
        // missing set is reported instead of silently skipping it.
        self::assertStringContainsString('does not index skills', $display);
        self::assertStringContainsString('content-only', $display);
        self::assertSame(Command::FAILURE, $exitCode);
    }

    /**
     * @param array<string, bool> $sites site identifier => carries the skills index queue
     */
    private function commandFor(array $sites): IndexSolrCommand
    {
        $solrSites = [];
        foreach ($sites as $identifier => $indexesSkills) {
            $configuration = $this->createMock(TypoScriptConfiguration::class);
            $configuration->method('getIndexQueueConfigurationIsEnabled')
                ->willReturnCallback(
                    static fn(string $name): bool => $name === SkillsIndexQueue::CONFIGURATION && $indexesSkills,
                );

            $coreSite = $this->createMock(CoreSite::class);
            $coreSite->method('getIdentifier')->willReturn($identifier);

            $site = $this->createMock(Site::class);
            $site->method('getSolrConfiguration')->willReturn($configuration);
            $site->method('getTypo3SiteObject')->willReturn($coreSite);
            $site->method('getLabel')->willReturn($identifier);
            $site->method('getRootPageId')->willReturn(1);

            $solrSites[] = $site;
        }

        $repository = $this->createMock(SiteRepository::class);
        $repository->method('getAvailableSites')->willReturn($solrSites);

        return new IndexSolrCommand(
            $repository,
            $this->createMock(QueueInitializationService::class),
            // Final, and never reached on these paths: nothing is indexed once a
            // site is skipped or the run is refused.
            (new \ReflectionClass(SkillsIndexQueue::class))->newInstanceWithoutConstructor(),
        );
    }
}
