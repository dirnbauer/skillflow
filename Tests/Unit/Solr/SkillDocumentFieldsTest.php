<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Unit\Solr;

use PHPUnit\Framework\TestCase;
use Webconsulting\Skillflow\Solr\SkillDocumentFields;

final class SkillDocumentFieldsTest extends TestCase
{
    private SkillDocumentFields $fields;

    protected function setUp(): void
    {
        $this->fields = new SkillDocumentFields();
    }

    public function testMapsSearchableFrontMatterToSolrFields(): void
    {
        $record = [
            'source' => 7,
            'allowed_tools' => '["Read","Search"]',
            'raw_frontmatter' => json_encode([
                'category' => 'SEO',
                'license' => 'MIT',
                'version' => 2,
                'tags' => ['TYPO3', 'content'],
                'engine' => 'classic',
            ], JSON_THROW_ON_ERROR),
        ];

        self::assertSame([
            'license_stringS' => 'MIT',
            'version_stringS' => '2',
            'category_stringS' => 'SEO',
            'tags_stringM' => ['TYPO3', 'content'],
            'allowedTools_stringM' => ['Read', 'Search'],
            'sourceUid_stringS' => '7',
            'sourceType_stringS' => 'github',
            'sourceTitle_stringS' => 'webconsulting skills',
        ], $this->fields->fromRecord($record, ['type' => 'github', 'title' => 'webconsulting skills']));
    }

    public function testNormalizesTagsAndDropsEmptyMetadataValues(): void
    {
        $record = [
            'raw_frontmatter' => json_encode([
                'category' => '  ',
                'license' => null,
                'tags' => [' TYPO3 ', '', 'TYPO3', null, ' search '],
            ], JSON_THROW_ON_ERROR),
        ];

        self::assertSame(['tags_stringM' => ['TYPO3', 'search']], $this->fields->fromRecord($record));
    }

    public function testReturnsNoFieldsForMissingOrInvalidMetadata(): void
    {
        self::assertSame([], $this->fields->fromRecord([]));
        self::assertSame([], $this->fields->fromRecord(['raw_frontmatter' => '{invalid']));
        self::assertSame([], $this->fields->fromRecord(['raw_frontmatter' => '"scalar"']));
    }

    public function testCatalogueOverridesTakePrecedenceWithoutChangingImportedMetadata(): void
    {
        $record = [
            'tx_skillflow_search_category' => ' TYPO3 ',
            'tx_skillflow_search_tags' => ' content, search, content ',
            'raw_frontmatter' => '{"category":"Other","tags":["old"],"metadata":{"license":"MIT"}}',
        ];

        self::assertSame([
            'license_stringS' => 'MIT',
            'category_stringS' => 'TYPO3',
            'tags_stringM' => ['content', 'search'],
        ], $this->fields->fromRecord($record));
    }

    public function testEmptyOverridesUseNestedFrontmatter(): void
    {
        $record = [
            'tx_skillflow_search_category' => ' ',
            'tx_skillflow_search_tags' => ', ',
            'raw_frontmatter' => '{"metadata":{"category":"TYPO3","tags":["content","search"]}}',
        ];

        self::assertSame([
            'category_stringS' => 'TYPO3',
            'tags_stringM' => ['content', 'search'],
        ], $this->fields->fromRecord($record));
    }
}
