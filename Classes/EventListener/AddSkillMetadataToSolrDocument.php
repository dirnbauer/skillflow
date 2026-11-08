<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\EventListener;

use ApacheSolrForTypo3\Solr\Event\Indexing\BeforeDocumentIsProcessedForIndexingEvent;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use Webconsulting\Skillflow\Service\SkillCatalogRepository;
use Webconsulting\Skillflow\Solr\SkillDocumentFields;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Adds what TypoScript cannot map directly: front matter values, the fields
 * the skills search matches while a name is typed, and the facts of the
 * skill catalogue that describes the skill's source (topic, publisher,
 * audience, evals, last change; see Documentation/Search/Catalogue.rst).
 */
#[AsEventListener(
    identifier: 'skillflow/solr-metadata',
    event: BeforeDocumentIsProcessedForIndexingEvent::class,
)]
final readonly class AddSkillMetadataToSolrDocument
{
    private const string SKILL_TABLE = 'tx_nrllm_skill';

    public function __construct(
        private SkillDocumentFields $skillDocumentFields,
        private ConnectionPool $connectionPool,
        private SkillCatalogRepository $catalogs,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

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
        $record = Typed::stringKeyedArray($record);

        $source = [];
        $sourceUid = Typed::int($record['source'] ?? 0);
        if ($sourceUid > 0) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrllm_skill_source');
            $source = $queryBuilder->select('title', 'type', 'url')->from('tx_nrllm_skill_source')
                ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($sourceUid, ParameterType::INTEGER)))
                ->executeQuery()->fetchAssociative() ?: [];
        }

        $fields = $this->skillDocumentFields->fromRecord($record, $source);

        $groupTitle = '';
        $catalog = $this->catalogs->findForRepository(Typed::string($source['url'] ?? ''), $event->getSite());
        $facts = $catalog?->skill(Typed::string($record['name'] ?? ''));
        if ($catalog !== null && $facts !== null) {
            $group = $catalog->group($facts->group);
            if ($group !== null) {
                // The core of each language gets the topic in its language.
                $groupTitle = $this->languageServiceFactory->createFromSiteLanguage($event->getSiteLanguage())->sL($group->title);
            }
            $fields = [
                ...$fields,
                ...$this->skillDocumentFields->catalogFields($facts, $catalog, $groupTitle, Typed::string($fields['license_stringS'] ?? '')),
            ];
        }
        $fields = [...$fields, ...$this->skillDocumentFields->searchFields($record, $groupTitle)];

        $document = $event->getDocument();
        foreach ($fields as $fieldName => $value) {
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
