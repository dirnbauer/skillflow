<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webconsulting\Skillflow\Service\SkillDocumentLinkResolver;

final class SkillDocumentLinkResolverTest extends TestCase
{
    private const string BLOB = 'https://github.com/dirnbauer/typo3-skills/blob/abc123/';
    private const string RAW = 'https://github.com/dirnbauer/typo3-skills/raw/abc123/';

    /**
     * @return iterable<string, array{string, bool, string|null}>
     */
    public static function linkProvider(): iterable
    {
        yield 'a file next to the skill' => ['references/full-guide.md', false, self::BLOB . 'skills/typo3-seo/references/full-guide.md'];
        yield 'dot segments are resolved' => ['./assets/../rules/10-graph-protocol.md', false, self::BLOB . 'skills/typo3-seo/rules/10-graph-protocol.md'];
        yield 'the anchor and query are kept' => ['SKILL-EU.md?plain=1#scope', false, self::BLOB . 'skills/typo3-seo/SKILL-EU.md?plain=1#scope'];
        yield 'spaces are encoded' => ['assets/My%20Template.md', false, self::BLOB . 'skills/typo3-seo/assets/My%20Template.md'];
        yield 'another skill with a page' => ['../typo3-solr/SKILL.md#facets', false, '/skill/typo3-solr/#facets'];
        yield 'another skill without a page' => ['../security-audit/SKILL.md', false, self::BLOB . 'skills/security-audit/SKILL.md'];
        yield 'an image loads the raw file' => ['assets/infographic.png', true, self::RAW . 'skills/typo3-seo/assets/infographic.png'];
        yield 'an image never becomes a page' => ['../typo3-solr/SKILL.md', true, self::RAW . 'skills/typo3-solr/SKILL.md'];
        yield 'a path out of the repository loses its link' => ['../../../etc/passwd', false, ''];
        yield 'an absolute URL stays' => ['https://typo3.org/', false, null];
        yield 'a mail address stays' => ['mailto:info@example.org', false, null];
        yield 'an in-page anchor stays' => ['#usage', false, null];
        yield 'a root-relative path stays' => ['/imprint', false, null];
        yield 'a protocol-relative URL stays' => ['//cdn.example.org/a.png', true, null];
    }

    #[DataProvider('linkProvider')]
    public function testRelativeLinksPointToSkillPagesOrTheRepository(string $url, bool $isImage, ?string $expected): void
    {
        self::assertSame($expected, self::resolver(self::BLOB, self::RAW)->resolve($url, $isImage));
    }

    public function testWithoutARepositoryUrlARelativeLinkLosesItsLink(): void
    {
        $resolver = self::resolver('', '');

        self::assertSame('', $resolver->resolve('references/full-guide.md', false));
        self::assertSame('/skill/typo3-solr/', $resolver->resolve('../typo3-solr/SKILL.md', false));
    }

    /**
     * @return iterable<string, array{string, string, array{0: string, 1: string}}>
     */
    public static function repositoryProvider(): iterable
    {
        yield 'GitHub' => ['https://github.com/o/r', 'main', ['https://github.com/o/r/blob/main/', 'https://github.com/o/r/raw/main/']];
        yield 'GitHub with .git and a slash' => ['https://github.com/o/r.git/', 'abc', ['https://github.com/o/r/blob/abc/', 'https://github.com/o/r/raw/abc/']];
        yield 'GitLab' => ['https://gitlab.webconsulting.at/extensions/skills', 'v1', ['https://gitlab.webconsulting.at/extensions/skills/-/blob/v1/', 'https://gitlab.webconsulting.at/extensions/skills/-/raw/v1/']];
        yield 'an unknown host' => ['https://git.example.org/o/r', 'main', ['', '']];
        yield 'no revision' => ['https://github.com/o/r', '', ['', '']];
        yield 'not https' => ['git@github.com:o/r.git', 'main', ['', '']];
    }

    /**
     * @param array{0: string, 1: string} $expected
     */
    #[DataProvider('repositoryProvider')]
    public function testRepositoryBaseUrls(string $repositoryUrl, string $revision, array $expected): void
    {
        self::assertSame($expected, SkillDocumentLinkResolver::repositoryBaseUrls($repositoryUrl, $revision));
    }

    private static function resolver(string $blob, string $raw): SkillDocumentLinkResolver
    {
        return new SkillDocumentLinkResolver(
            'skills/typo3-seo/SKILL.md',
            $blob,
            $raw,
            static fn(string $path): ?string => $path === 'skills/typo3-solr/SKILL.md' ? '/skill/typo3-solr/' : null,
        );
    }
}
