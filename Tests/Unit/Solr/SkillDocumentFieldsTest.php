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
            'category_stringS' => 'SEO',
            'license_stringS' => 'MIT',
            'version_stringS' => '2',
            'tags_stringM' => ['TYPO3', 'content'],
            'allowedTools_stringM' => ['Read', 'Search'],
            'sourceUid_stringS' => '7',
            'sourceType_stringS' => 'nr_llm',
        ], $this->fields->fromRecord($record));
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

        self::assertSame(['tags_stringM' => ['TYPO3', 'search'], 'sourceType_stringS' => 'nr_llm'], $this->fields->fromRecord($record));
    }

    public function testReturnsNoFieldsForMissingOrInvalidMetadata(): void
    {
        self::assertSame(['sourceType_stringS' => 'nr_llm'], $this->fields->fromRecord([]));
        self::assertSame(['sourceType_stringS' => 'nr_llm'], $this->fields->fromRecord(['raw_frontmatter' => 'not: [yaml']));
        self::assertSame(['sourceType_stringS' => 'nr_llm'], $this->fields->fromRecord(['raw_frontmatter' => '"scalar"']));
    }
}
