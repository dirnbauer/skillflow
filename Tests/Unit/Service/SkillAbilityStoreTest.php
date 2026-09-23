<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webconsulting\Skillflow\Service\SkillAbilityStore;

final class SkillAbilityStoreTest extends TestCase
{
    /** @return iterable<string, array{mixed, list<string>}> */
    public static function declarations(): iterable
    {
        yield 'YAML list' => [['news/list', 'solr/index-queue'], ['news/list', 'solr/index-queue']];
        yield 'comma separated' => ['news/list, solr/index-queue', ['news/list', 'solr/index-queue']];
        yield 'space separated' => ["news/list  solr/index-queue\n", ['news/list', 'solr/index-queue']];
        yield 'trimmed and de-duplicated in order' => [[' news/list ', 'solr/index-queue', 'news/list'], ['news/list', 'solr/index-queue']];
        yield 'non-strings ignored' => [['news/list', 42, null, ['nested'], ''], ['news/list']];
        yield 'empty list' => [[], []];
        yield 'null' => [null, []];
        yield 'mapping values' => [['a' => 'news/list'], ['news/list']];
    }

    /** @param list<string> $expected */
    #[DataProvider('declarations')]
    public function testDeclarationIsParsed(mixed $declaration, array $expected): void
    {
        self::assertSame($expected, SkillAbilityStore::parseDeclaration($declaration));
    }

    public function testFrontmatterWithoutAbilitiesKeyDeclaresNothing(): void
    {
        self::assertNull(SkillAbilityStore::fromFrontmatterJson('{"name":"Review"}'));
        self::assertNull(SkillAbilityStore::fromFrontmatterJson(''));
        self::assertNull(SkillAbilityStore::fromFrontmatterJson('not json'));
        self::assertSame([], SkillAbilityStore::fromFrontmatterJson('{"abilities":[]}'));
        self::assertSame(['news/list'], SkillAbilityStore::fromFrontmatterJson('{"abilities":["news/list"]}'));
    }

    public function testStoredListWinsOverTheFrontMatter(): void
    {
        $row = [
            SkillAbilityStore::FIELD => '["system/site-info"]',
            'raw_frontmatter' => '{"abilities":["news/list"]}',
        ];
        self::assertSame(['system/site-info'], SkillAbilityStore::fromSkillRow($row));

        $row[SkillAbilityStore::FIELD] = '[]';
        self::assertSame([], SkillAbilityStore::fromSkillRow($row), 'A stored empty list means "declares none"');
    }

    public function testUnsyncedSkillIsReadFromItsFrontMatter(): void
    {
        self::assertSame(['news/list'], SkillAbilityStore::fromSkillRow(['raw_frontmatter' => '{"abilities":"news/list"}']));
        self::assertSame(['news/list'], SkillAbilityStore::fromSkillRow([SkillAbilityStore::FIELD => '', 'raw_frontmatter' => '{"abilities":["news/list"]}']));
        self::assertSame([], SkillAbilityStore::fromSkillRow([SkillAbilityStore::FIELD => null, 'raw_frontmatter' => null]));
    }
}
