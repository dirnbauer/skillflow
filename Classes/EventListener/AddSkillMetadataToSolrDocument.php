<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\EventListener;

use ApacheSolrForTypo3\Solr\Event\Indexing\BeforeDocumentIsProcessedForIndexingEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Copies values from the skill record's JSON "metadata" column into the
 * Solr document as dynamic facet fields while the index queue item for a
 * tx_skillflow_skill record is being processed.
 *
 * Listener-set dynamic fields (single scalars): category_stringS,
 * license_stringS, version_stringS. Multi-value: tags_stringM.
 *
 * The "source_type" column is a real DB field mapped via TypoScript, so it
 * is intentionally not touched here.
 */
#[AsEventListener(
    identifier: 'skillflow/solr-metadata',
    event: BeforeDocumentIsProcessedForIndexingEvent::class,
)]
final class AddSkillMetadataToSolrDocument
{
    private const SKILL_TABLE = 'tx_skillflow_skill';

    public function __invoke(BeforeDocumentIsProcessedForIndexingEvent $event): void
    {
        $item = $event->getIndexQueueItem();
        if ($item->getType() !== self::SKILL_TABLE) {
            return;
        }

        $record = $item->getRecord();
        if (!is_array($record) || !isset($record['metadata'])) {
            return;
        }

        $rawMetadata = $record['metadata'];
        if (!is_string($rawMetadata) || $rawMetadata === '') {
            return;
        }

        $metadata = json_decode($rawMetadata, true);
        if (!is_array($metadata)) {
            return;
        }

        $document = $event->getDocument();

        foreach (['category' => 'category_stringS', 'license' => 'license_stringS', 'version' => 'version_stringS'] as $metadataKey => $solrField) {
            if (isset($metadata[$metadataKey]) && is_scalar($metadata[$metadataKey])) {
                $document->setField($solrField, (string)$metadata[$metadataKey]);
            }
        }

        if (isset($metadata['tags']) && is_array($metadata['tags'])) {
            foreach ($metadata['tags'] as $tag) {
                if (is_scalar($tag) && (string)$tag !== '') {
                    $document->addField('tags_stringM', (string)$tag);
                }
            }
        }
    }
}
