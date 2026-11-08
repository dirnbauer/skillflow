<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Unit\ViewHelpers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webconsulting\Skillflow\ViewHelpers\Search\HighlightViewHelper;

final class HighlightViewHelperTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function cases(): iterable
    {
        yield 'word prefix after a hyphen' => ['typo3-upgrade-run', 'upg', 'typo3-<mark>upg</mark>rade-run'];
        yield 'case-insensitive, text keeps its case' => ['Upgrade TYPO3', 'typo3 upgrade', '<mark>Upgrade</mark> <mark>TYPO3</mark>'];
        yield 'not inside a word' => ['backup and upgrade', 'up', 'backup and <mark>up</mark>grade'];
        yield 'longest word wins' => ['upgrade', 'up upgrade', '<mark>upgrade</mark>'];
        yield 'one letter marks nothing' => ['a skill', 'a', 'a skill'];
        yield 'text is escaped' => ['<b>run</b> & "go"', 'run', '&lt;b&gt;<mark>run</mark>&lt;/b&gt; &amp; &quot;go&quot;'];
        yield 'query syntax is not a pattern' => ['a.b (cd)', '.* (cd', 'a.b (<mark>cd</mark>)'];
        yield 'empty query' => ['typo3-seo', '', 'typo3-seo'];
    }

    #[DataProvider('cases')]
    public function testHighlight(string $text, string $query, string $expected): void
    {
        self::assertSame($expected, HighlightViewHelper::highlight($text, $query));
    }

    public function testWordsAreUniqueLongestFirst(): void
    {
        self::assertSame(['upgrade', 'run', 'up'], HighlightViewHelper::words('up Run upgrade run x'));
    }
}
