<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\EventListener;

use ApacheSolrForTypo3\Solr\Event\Indexing\BeforeDocumentIsProcessedForIndexingEvent;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\ConnectionPool;
use Webconsulting\Skillflow\Solr\SkillDocumentFields;
use Webconsulting\Skillflow\Support\Typed;

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
        private readonly ConnectionPool $connectionPool,
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
        $source = [];
        $sourceUid = Typed::int($record['source'] ?? 0);
        if ($sourceUid > 0) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrllm_skill_source');
            $source = $queryBuilder->select('title', 'type')->from('tx_nrllm_skill_source')
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($sourceUid, ParameterType::INTEGER)))
                ->executeQuery()->fetchAssociative() ?: [];
        }

        foreach ($this->skillDocumentFields->fromRecord(Typed::stringKeyedArray($record), $source) as $fieldName => $value) {
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
