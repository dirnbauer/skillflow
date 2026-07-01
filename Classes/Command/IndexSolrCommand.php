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

/**
 * Initializes and indexes the skillflow "skills" Solr index queue.
 *
 * EXT:solr 14.0.0-beta4 ships no solr:* console commands. This command replicates
 * what the scheduler tasks do, but scoped to the "skills" indexing configuration:
 *  - ReIndexTask: rebuild the queue via QueueInitializationService
 *  - IndexQueueWorkerTask: process queued items via IndexService::indexItems()
 *
 * Note on the indexing path: in beta4 the worker task no longer calls
 * Indexer::index(Item) directly. IndexService::indexItems(int $limit) is the
 * high-level entry point; it fetches queued items via Queue::getItemsToIndex()
 * and delegates to the new IndexingService, which sets up the required frontend
 * context through TYPO3 core sub-requests (FrontendApplication::handle()).
 * Driving IndexService is therefore both the supported and the only self-contained
 * way to index in beta4 — and it handles the FE-context requirement internally,
 * so this CLI command needs no extra frontend bootstrap of its own.
 */
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

        $queue = GeneralUtility::makeInstance(Queue::class);
        $hasErrors = false;

        foreach ($sites as $site) {
            $io->section(sprintf(
                'Site: %s (%s, rootPage %d)',
                $site->getLabel(),
                $site->getTypo3SiteObject()->getIdentifier(),
                $site->getRootPageId()
            ));

            try {
                // (a) Initialize/rebuild the queue for this site + the "skills" configuration.
                // QueueInitializationService truncates the existing "skills" rows for the site
                // (clearQueueOnInitialization defaults to true) and re-discovers items.
                $initializationStatus = $this->queueInitializationService
                    ->initializeBySiteAndIndexConfiguration($site, self::INDEXING_CONFIGURATION);

                $initialized = $initializationStatus[self::INDEXING_CONFIGURATION] ?? false;
                if ($initialized !== true) {
                    $io->error(sprintf(
                        'Index queue initialization for configuration "%s" failed. '
                        . 'Verify that plugin.tx_solr.index.queue.%s is enabled in the site TypoScript.',
                        self::INDEXING_CONFIGURATION,
                        self::INDEXING_CONFIGURATION
                    ));
                    $hasErrors = true;
                    continue;
                }

                $createdCount = $queue->getStatisticsBySite($site, self::INDEXING_CONFIGURATION)->getTotalCount();
                $io->writeln(sprintf('Created %d queue item(s) for "%s".', $createdCount, self::INDEXING_CONFIGURATION));

                if ($createdCount === 0) {
                    $io->writeln('Nothing to index.');
                    continue;
                }

                // (b) Index the queued items. IndexService fetches them via
                // Queue::getItemsToIndex() and indexes them through IndexingService.
                $indexService = GeneralUtility::makeInstance(IndexService::class, $site);
                $indexService->indexItems($limit);

                // Report results from the post-run queue statistics for this site.
                // The statistics are not scoped to a single configuration in this
                // run because IndexService processes the whole site queue, but for
                // a "skills"-only site the "skills" filter reflects the relevant rows.
                $statistic = $queue->getStatisticsBySite($site, self::INDEXING_CONFIGURATION);
                $indexedCount = $statistic->getSuccessCount();
                $failedCount = $statistic->getFailedCount();
                $pendingCount = $statistic->getPendingCount();

                $io->writeln(sprintf(
                    'Indexed %d, failed %d, pending %d (%.2f%% complete).',
                    $indexedCount,
                    $failedCount,
                    $pendingCount,
                    $statistic->getSuccessPercentage()
                ));

                if ($failedCount > 0) {
                    $hasErrors = true;
                    $io->warning(sprintf('%d item(s) failed indexing. Check the Solr log for details.', $failedCount));
                }
            } catch (\Throwable $e) {
                $io->error($e->getMessage());
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
