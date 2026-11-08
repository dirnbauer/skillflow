<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\ViewHelpers\Catalog;

use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\Facets\OptionBased\AbstractOptionFacetItem;

/**
 * Puts the options of the topics facet in the order of the catalogue's
 * groups (topic 1 first), whatever order Solr returned them in. Options the
 * catalogue does not know come last, in their own order.
 *
 * ```
 *   <f:for each="{sf:catalog.sortOptions(options: facet.options)}" as="option">…</f:for>
 * ```
 */
final class SortOptionsViewHelper extends AbstractCatalogViewHelper
{
    #[\Override]
    public function initializeArguments(): void
    {
        $this->registerArgument('options', 'iterable', 'Facet options (EXT:solr facet items)', true);
    }

    /**
     * @return list<AbstractOptionFacetItem>
     */
    #[\Override]
    public function render(): array
    {
        $positions = [];
        foreach ($this->siteCatalogs() as $catalog) {
            foreach ($catalog->groups as $key => $group) {
                $positions[$key] ??= $group->position;
            }
        }

        $options = [];
        foreach (is_iterable($this->arguments['options'] ?? null) ? $this->arguments['options'] : [] as $option) {
            if ($option instanceof AbstractOptionFacetItem) {
                $options[] = $option;
            }
        }
        $order = array_flip(array_map(static fn(AbstractOptionFacetItem $option): string => spl_object_hash($option), $options));
        usort($options, static fn(AbstractOptionFacetItem $a, AbstractOptionFacetItem $b): int => [$positions[(string)$a->getValue()] ?? PHP_INT_MAX, $order[spl_object_hash($a)]]
            <=> [$positions[(string)$b->getValue()] ?? PHP_INT_MAX, $order[spl_object_hash($b)]]);

        return $options;
    }
}
