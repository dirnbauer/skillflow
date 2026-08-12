<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Command;

use ApacheSolrForTypo3\Solr\Domain\Index\IndexService;
use ApacheSolrForTypo3\Solr\Domain\Index\Queue\QueueInitializationService;
use ApacheSolrForTypo3\Solr\Domain\Site\Site;
use ApacheSolrForTypo3\Solr\Domain\Site\SiteRepository;
use ApacheSolrForTypo3\Solr\IndexQueue\Queue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillflow\Support\Typed;

/** Rebuilds and processes Skillflow's "skills" Solr index queue. */
#[AsCommand(
    name: 'skillflow:solr:index',
    description: 'Initialize and index the skillflow skills Solr index queue'
)]
final class IndexSolrCommand extends Command
{
    private const INDEXING_CONFIGURATION = 'skills';

    public function __construct(
        private readonly SiteRepository $siteRepository,
        private readonly QueueInitializationService $queueInitializationService,
    ) {
        parent::__construct();
    }

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
        $queue = GeneralUtility::makeInstance(Queue::class);

        foreach ($sites as $site) {
            if (!$this->indexSite($site, $queue, $limit, $io)) {
                $hasErrors = true;
            }
        }

        if ($hasErrors) {
            $io->warning('Indexing finished with errors.');
            return Command::FAILURE;
        }

        $io->success('Indexing finished.');
        return Command::SUCCESS;
    }

    private function indexSite(Site $site, Queue $queue, int $limit, SymfonyStyle $io): bool
    {
        $io->section(sprintf(
            'Site: %s (%s, root page %d)',
            $site->getLabel(),
            $site->getTypo3SiteObject()->getIdentifier(),
            $site->getRootPageId(),
        ));

        try {
            $createdCount = $this->initializeQueue($site, $queue);
            $io->writeln(sprintf(
                'Created %d queue item(s) for "%s".',
                $createdCount,
                self::INDEXING_CONFIGURATION,
            ));

            if ($createdCount === 0) {
                $io->writeln('Nothing to index.');
                return true;
            }

            return $this->processQueue($site, $queue, $limit, $io);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return false;
        }
    }

    private function initializeQueue(Site $site, Queue $queue): int
    {
        $status = $this->queueInitializationService
            ->initializeBySiteAndIndexConfiguration($site, self::INDEXING_CONFIGURATION);

        if (($status[self::INDEXING_CONFIGURATION] ?? false) !== true) {
            throw new \RuntimeException(sprintf(
                'Could not initialize "%s". Verify that plugin.tx_solr.index.queue.%s is enabled.',
                self::INDEXING_CONFIGURATION,
                self::INDEXING_CONFIGURATION,
            ));
        }

        return $queue->getStatisticsBySite($site, self::INDEXING_CONFIGURATION)->getTotalCount();
    }

    private function processQueue(Site $site, Queue $queue, int $limit, SymfonyStyle $io): bool
    {
        $indexService = GeneralUtility::makeInstance(IndexService::class, $site);
        $indexingSucceeded = $indexService->indexItems($limit);
        $statistics = $queue->getStatisticsBySite($site, self::INDEXING_CONFIGURATION);

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
        }

        return $indexingSucceeded && $statistics->getFailedCount() === 0;
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
