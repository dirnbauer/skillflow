<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Solr;

use ApacheSolrForTypo3\Solr\IndexQueue\Initializer\Record;

/** Includes nr_llm's root records alongside the site's configured storage pages. */
final class SkillsRecordInitializer extends Record
{
    /** @return int[] */
    #[\Override]
    protected function getPages(): array
    {
        /** @var int[] $pages */
        $pages = parent::getPages();
        return array_unique([0, ...$pages]);
    }
}
