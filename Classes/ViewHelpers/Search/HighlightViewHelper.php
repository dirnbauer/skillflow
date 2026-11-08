<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\ViewHelpers\Search;

use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Marks the words of a search query in a text, as prefixes of words: "up"
 * marks the start of "upgrade" in "typo3-upgrade-run". The text is escaped
 * here, so the output is safe HTML with <mark> elements and nothing else.
 *
 * ```
 *   <sf:search.highlight text="{document.title}" query="{resultSet.usedQuery.rawSearchTerm}" />
 *   <sf:search.highlight text="{document.description}" query="{q}" maxLength="180" />
 * ```
 */
final class HighlightViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    #[\Override]
    public function initializeArguments(): void
    {
        $this->registerArgument('text', 'string', 'The text to show', true);
        $this->registerArgument('query', 'string', 'The search words; nothing is marked without them', false, '');
        $this->registerArgument('maxLength', 'int', 'Crops the text at a word boundary to this many characters, 0 keeps it whole', false, 0);
    }

    #[\Override]
    public function render(): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', Typed::string($this->arguments['text'] ?? '')) ?? '');
        $maxLength = Typed::int($this->arguments['maxLength'] ?? 0);
        if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
            $cropped = mb_substr($text, 0, $maxLength);
            $space = mb_strrpos($cropped, ' ');
            $text = rtrim($space !== false && $space > $maxLength * 0.6 ? mb_substr($cropped, 0, $space) : $cropped, ' ,.;:-') . '…';
        }

        return self::highlight($text, Typed::string($this->arguments['query'] ?? ''));
    }

    public static function highlight(string $text, string $query): string
    {
        $words = self::words($query);
        if ($words === [] || $text === '') {
            return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5);
        }
        $pattern = '/(?<![\p{L}\p{N}])(' . implode('|', array_map(static fn(string $word): string => preg_quote($word, '/'), $words)) . ')/iu';
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        $html = '';
        foreach ($parts as $index => $part) {
            $escaped = htmlspecialchars($part, ENT_QUOTES | ENT_HTML5);
            // preg_split puts every captured match at an odd index.
            $html .= $index % 2 === 1 ? '<mark>' . $escaped . '</mark>' : $escaped;
        }

        return $html;
    }

    /**
     * The words of a query worth marking: two characters or more, longest
     * first so "upgrade" wins over "up".
     *
     * @return list<string>
     */
    public static function words(string $query): array
    {
        $words = array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [],
            static fn(string $word): bool => mb_strlen($word) >= 2,
        );
        $words = array_values(array_unique($words));
        usort($words, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $words;
    }
}
