<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webconsulting\Skillflow\Service\MarkdownRenderer;
use Webconsulting\Skillflow\Service\RelativeLinkResolverInterface;

final class MarkdownRendererTest extends TestCase
{
    private const string DOCUMENT = "# Title\n\nIntro\n\n## Section\n\n###### Deepest\n\nSetext\n======\n";

    public function testWithoutOffsetTheHeadingsKeepTheirLevels(): void
    {
        $html = new MarkdownRenderer()->toHtml(self::DOCUMENT);

        self::assertSame(['h1', 'h2', 'h6', 'h1'], self::headingTags($html));
    }

    /**
     * @return iterable<string, array{int, list<string>}>
     */
    public static function offsetProvider(): iterable
    {
        yield 'one level: # becomes h2, the page keeps its only h1' => [1, ['h2', 'h3', 'h6', 'h2']];
        yield 'two levels: under a section h2' => [2, ['h3', 'h4', 'h6', 'h3']];
        yield 'past h6 stays h6' => [9, ['h6', 'h6', 'h6', 'h6']];
        yield 'a negative offset changes nothing' => [-1, ['h1', 'h2', 'h6', 'h1']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('offsetProvider')]
    public function testTheOffsetMovesEveryHeadingDownAndStopsAtH6(int $offset, array $expected): void
    {
        $html = new MarkdownRenderer()->toHtml(self::DOCUMENT, $offset);

        self::assertSame($expected, self::headingTags($html));
        self::assertStringContainsString('<' . $expected[0] . '>Title</' . $expected[0] . '>', $html);
    }

    public function testTheOffsetDoesNotLeakIntoTheNextDocument(): void
    {
        $renderer = new MarkdownRenderer();
        $renderer->toHtml('# Shifted', 1);

        self::assertSame("<h1>Plain</h1>\n", $renderer->toHtml('# Plain'));
    }

    public function testRawHtmlIsStillEscapedWithAnOffset(): void
    {
        $html = new MarkdownRenderer()->toHtml("# Title\n\n<h1>raw</h1>\n\n[x](javascript:alert(1))", 1);

        self::assertStringNotContainsString('<h1>', $html);
        self::assertStringContainsString('&lt;h1&gt;raw&lt;/h1&gt;', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    public function testTheLinkResolverRewritesLinksAndImages(): void
    {
        $links = new class implements RelativeLinkResolverInterface {
            #[\Override]
            public function resolve(string $url, bool $isImage): ?string
            {
                return match ($url) {
                    'guide.md' => 'https://example.org/guide.md',
                    'chart.png' => $isImage ? 'https://example.org/raw/chart.png' : null,
                    'gone.md', 'gone.png' => '',
                    default => null,
                };
            }
        };

        $html = new MarkdownRenderer()->toHtml(
            'See [the *guide*](guide.md), [gone](gone.md), [kept](https://typo3.org) and ![chart](chart.png) ![lost image](gone.png).',
            0,
            $links,
        );

        self::assertStringContainsString('<a href="https://example.org/guide.md">the <em>guide</em></a>', $html);
        self::assertStringContainsString(', gone, ', $html);
        self::assertStringNotContainsString('gone.md', $html);
        self::assertStringContainsString('<a href="https://typo3.org">kept</a>', $html);
        self::assertStringContainsString('<img src="https://example.org/raw/chart.png" alt="chart" />', $html);
        self::assertStringContainsString('lost image', $html);
        self::assertStringNotContainsString('gone.png', $html);
    }

    public function testRewrittenLinksAreStillCheckedForUnsafeSchemes(): void
    {
        $links = new class implements RelativeLinkResolverInterface {
            #[\Override]
            public function resolve(string $url, bool $isImage): string
            {
                return 'javascript:alert(1)';
            }
        };

        self::assertStringNotContainsString('javascript:', new MarkdownRenderer()->toHtml('[x](guide.md)', 0, $links));
    }

    public function testEmptyMarkdownRendersNothing(): void
    {
        self::assertSame('', new MarkdownRenderer()->toHtml('', 1));
    }

    /**
     * @return list<string>
     */
    private static function headingTags(string $html): array
    {
        preg_match_all('/<(h[1-6])\b/', $html, $matches);

        return $matches[1];
    }
}
