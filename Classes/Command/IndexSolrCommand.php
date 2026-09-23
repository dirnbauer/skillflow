<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Command;

use ApacheSolrForTypo3\Solr\Domain\Index\IndexService;
use ApacheSolrForTypo3\Solr\Domain\Index\Queue\QueueInitializationService;
use ApacheSolrForTypo3\Solr\Domain\Site\Site;
use ApacheSolrForTypo3\Solr\Domain\Site\SiteRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillflow\Solr\SkillsIndexQueue;
use Webconsulting\Skillflow\Support\Typed;

/** Rebuilds and processes Skillflow's "skills" Solr index queue. */
#[AsCommand(
    name: 'skillflow:solr:index',
    description: 'Initialize and index the skillflow skills Solr index queue'
)]
final class IndexSolrCommand extends Command
{
    public function __construct(
        private readonly SiteRepository $siteRepository,
        private readonly QueueInitializationService $queueInitializationService,
        private readonly SkillsIndexQueue $queue,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption(
                'site',
                's',
                InputOption::VALUE_REQUIRED,
                'Site identifier to index. If omitted, all Solr-enabled sites are processed.'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_REQUIRED,
                'Maximum number of queue items to index per site',
                500
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $siteIdentifier = $input->getOption('site');
        $siteIdentifier = is_string($siteIdentifier) && $siteIdentifier !== '' ? $siteIdentifier : null;
        $limit = max(1, Typed::int($input->getOption('limit')));

        try {
            $sites = $this->resolveSites($siteIdentifier);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($sites === []) {
            $io->warning(
                $siteIdentifier !== null
                    ? sprintf('No Solr-enabled site found for identifier "%s".', $siteIdentifier)
                    : 'No Solr-enabled sites found.'
            );
            return Command::FAILURE;
        }

        $hasErrors = false;
        $indexedSites = 0;
        $skippedSites = [];

        foreach ($sites as $site) {
            $identifier = $site->getTypo3SiteObject()->getIdentifier();

            // A site can be Solr-enabled for its own content without indexing
            // skills. Only sites carrying webconsulting/skillflow-solr have the
            // queue configuration, and the others must not fail the run — in a
            // multi-site installation that would make the command always exit 1.
            // An explicitly requested site is different: there the missing set
            // is exactly what the caller needs to hear about.
            if (!$this->indexesSkills($site)) {
                if ($siteIdentifier !== null) {
                    $io->error(sprintf(
                        'Site "%s" does not index skills. Enable the webconsulting/skillflow-solr site set.',
                        $identifier,
                    ));

                    return Command::FAILURE;
                }

                $skippedSites[] = $identifier;
                continue;
            }

            ++$indexedSites;
            if (!$this->indexSite($site, $limit, $io)) {
                $hasErrors = true;
            }
        }

        if ($skippedSites !== []) {
            $io->writeln(sprintf(
                'Skipped %d site(s) without the webconsulting/skillflow-solr site set: %s.',
                count($skippedSites),
                implode(', ', $skippedSites),
            ));
        }

        if ($hasErrors) {
            $io->warning('Indexing finished with errors.');
            return Command::FAILURE;
        }

        if ($indexedSites === 0) {
            $io->warning('No site indexes skills. Enable the webconsulting/skillflow-solr site set on at least one site.');

            return Command::FAILURE;
        }

        $io->success(sprintf('Indexing finished for %d site(s).', $indexedSites));

        return Command::SUCCESS;
    }

    /** Whether the site carries the skills index-queue configuration. */
    private function indexesSkills(Site $site): bool
    {
        return $site->getSolrConfiguration()
            ->getIndexQueueConfigurationIsEnabled(SkillsIndexQueue::CONFIGURATION);
    }

    private function indexSite(Site $site, int $limit, SymfonyStyle $io): bool
    {
        $io->section(sprintf(
            'Site: %s (%s, root page %d)',
            $site->getLabel(),
            $site->getTypo3SiteObject()->getIdentifier(),
            $site->getRootPageId(),
        ));

        try {
            $createdCount = $this->initializeQueue($site);
            $io->writeln(sprintf(
                'Created %d queue item(s) for "%s".',
                $createdCount,
                SkillsIndexQueue::CONFIGURATION,
            ));

            if ($createdCount === 0) {
                $io->writeln('Nothing to index.');
                return true;
            }

            return $this->processQueue($site, $limit, $io);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return false;
        }
    }

    private function initializeQueue(Site $site): int
    {
        $status = $this->queueInitializationService
            ->initializeBySiteAndIndexConfiguration($site, SkillsIndexQueue::CONFIGURATION);

        if (($status[SkillsIndexQueue::CONFIGURATION] ?? false) !== true) {
            throw new \RuntimeException(sprintf(
                'Could not initialize "%s". Verify that plugin.tx_solr.index.queue.%s is enabled.',
                SkillsIndexQueue::CONFIGURATION,
                SkillsIndexQueue::CONFIGURATION,
            ));
        }

        return $this->queue->getStatisticsBySite($site, SkillsIndexQueue::CONFIGURATION)->getTotalCount();
    }

    private function processQueue(Site $site, int $limit, SymfonyStyle $io): bool
    {
        $indexService = GeneralUtility::makeInstance(IndexService::class, $site, $this->queue);
        $indexingSucceeded = $indexService->indexItems($limit);
        $statistics = $this->queue->getStatisticsBySite($site, SkillsIndexQueue::CONFIGURATION);

        $io->writeln(sprintf(
            'Indexed %d, failed %d, pending %d (%.2f%% complete).',
            $statistics->getSuccessCount(),
            $statistics->getFailedCount(),
            $statistics->getPendingCount(),
            $statistics->getSuccessPercentage(),
        ));

        if ($statistics->getFailedCount() > 0) {
            $io->warning(sprintf(
                '%d item(s) failed indexing. Check the Solr log for details.',
                $statistics->getFailedCount(),
            ));
        } elseif (!$indexingSucceeded) {
            $io->warning('Solr reported an indexing or commit error. Check the Solr log for details.');
        } elseif ($statistics->getPendingCount() > 0) {
            $io->warning('The rebuild is incomplete. Increase --limit to include all skills.');
        }

        return $indexingSucceeded && $statistics->getFailedCount() === 0 && $statistics->getPendingCount() === 0;
    }

    /**
     * Resolves the Solr-enabled Site object(s) to index.
     *
     * @return Site[]
     */
    private function resolveSites(?string $siteIdentifier): array
    {
        $sites = $this->siteRepository->getAvailableSites();

        if ($siteIdentifier === null) {
            return array_values($sites);
        }

        foreach ($sites as $site) {
            if ($site->getTypo3SiteObject()->getIdentifier() === $siteIdentifier) {
                return [$site];
            }
        }

        return [];
    }
}
