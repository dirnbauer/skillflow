<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Unit\Solr;

use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testAllowedToolsAreToolNames(): void
    {
        // As nr_llm stores them: a rule with a space arrives in two pieces.
        $record = ['allowed_tools' => json_encode([
            'Bash(${CLAUDE_SKILL_DIR}/scripts/*)',
            'Bash(bash',
            '${CLAUDE_SKILL_DIR}/scripts/*)',
            'Bash(gh:*)',
            'Read',
            'mcp__typo3__pages',
            'Write',
        ], JSON_THROW_ON_ERROR)];

        self::assertSame(
            ['allowedTools_stringM' => ['Bash', 'Read', 'mcp__typo3__pages', 'Write']],
            $this->fields->fromRecord($record)
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function licences(): iterable
    {
        yield 'plain' => ['MIT', 'MIT'];
        yield 'dual with a slash' => ['MIT / CC-BY-SA-4.0', 'MIT / CC-BY-SA-4.0'];
        yield 'note after a semicolon' => ['MIT / CC-BY-SA-4.0; adapted sources retain their original licences', 'MIT / CC-BY-SA-4.0'];
        yield 'parentheses and a sentence' => ['(MIT AND CC-BY-SA-4.0). See LICENSE-MIT and LICENSE-CC-BY-SA-4.0', 'MIT AND CC-BY-SA-4.0'];
        yield 'parentheses only' => ['(MIT AND CC-BY-SA-4.0)', 'MIT AND CC-BY-SA-4.0'];
        yield 'versions keep their dots' => ['Apache-2.0 OR CC-BY-SA-4.0', 'Apache-2.0 OR CC-BY-SA-4.0'];
        yield 'nested parentheses stay' => ['(MIT OR (Apache-2.0 AND BSD-3-Clause))', '(MIT OR (Apache-2.0 AND BSD-3-Clause))'];
    }

    #[DataProvider('licences')]
    public function testLicenceIsTheExpressionWithoutItsNote(string $license, string $expected): void
    {
        $record = ['raw_frontmatter' => json_encode(['license' => $license], JSON_THROW_ON_ERROR)];

        self::assertSame(['license_stringS' => $expected], $this->fields->fromRecord($record));
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
