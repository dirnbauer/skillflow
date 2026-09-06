<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Solr;

use ApacheSolrForTypo3\Solr\Domain\Site\Site;
use ApacheSolrForTypo3\Solr\IndexQueue\Item;
use ApacheSolrForTypo3\Solr\IndexQueue\Queue;

/** Restricts the freshly initialized rebuild to the skills configuration. */
final class SkillsIndexQueue extends Queue
{
    public const CONFIGURATION = 'skills';

    /** @return Item[] */
    public function getItemsToIndex(Site $site, int $limit = 50): array
    {
        return $this->queueItemRepository->findItems(
            sites: [$site],
            indexQueueConfigurationNames: [self::CONFIGURATION],
            limit: $limit,
        );
    }
}
