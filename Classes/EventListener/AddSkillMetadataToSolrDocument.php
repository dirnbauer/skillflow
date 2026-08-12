<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\EventListener;

use ApacheSolrForTypo3\Solr\Event\Indexing\BeforeDocumentIsProcessedForIndexingEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use Webconsulting\Skillflow\Solr\SkillDocumentFields;

/**
 * Adds front-matter values that cannot be mapped directly with TypoScript.
 */
#[AsEventListener(
    identifier: 'skillflow/solr-metadata',
    event: BeforeDocumentIsProcessedForIndexingEvent::class,
)]
final class AddSkillMetadataToSolrDocument
{
    private const SKILL_TABLE = 'tx_nrllm_skill';

    public function __construct(
        private readonly SkillDocumentFields $skillDocumentFields,
    ) {
    }

    public function __invoke(BeforeDocumentIsProcessedForIndexingEvent $event): void
    {
        $item = $event->getIndexQueueItem();
        if ($item->getType() !== self::SKILL_TABLE) {
            return;
        }

        $record = $item->getRecord();
        if (!is_array($record)) {
            return;
        }

        $document = $event->getDocument();
        foreach ($this->skillDocumentFields->fromRecord($record) as $fieldName => $value) {
            if (is_array($value)) {
                foreach ($value as $itemValue) {
                    $document->addField($fieldName, $itemValue);
                }
                continue;
            }

            $document->setField($fieldName, $value);
        }
    }
}
