<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\ViewHelpers\Search;

use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\Result\SearchResult;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\SearchResultSet;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * The part of a skill's SKILL.md that matched the query, from Solr's
 * highlighting of the content field: escaped text with <mark> around the
 * matches, '' when Solr highlighted nothing. (EXT:solr's
 * s:document.highlightResult returns the whole field instead, which for a
 * skill is the whole SKILL.md.)
 *
 * ```
 *   <f:variable name="snippet" value="{sf:search.snippet(resultSet: resultSet, document: document)}" />
 * ```
 */
final class SnippetViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    #[\Override]
    public function initializeArguments(): void
    {
        $this->registerArgument('resultSet', SearchResultSet::class, 'The result set of the search', true);
        $this->registerArgument('document', SearchResult::class, 'The skill document', true);
        $this->registerArgument('fieldName', 'string', 'Highlighted field', false, 'content');
        $this->registerArgument('query', 'string', 'The search words', false, '');
        $this->registerArgument('exceptIn', 'string', 'Text the reader already sees (name, description): when a search word starts a word there, no snippet', false, '');
    }

    #[\Override]
    public function render(): string
    {
        $resultSet = $this->arguments['resultSet'];
        $document = $this->arguments['document'];
        if (!$resultSet instanceof SearchResultSet || !$document instanceof SearchResult || $resultSet->getUsedSearch() === null) {
            return '';
        }
        // Say why a skill matched only when its name and description do not.
        $seen = (string)$this->arguments['exceptIn'];
        if ($seen !== '' && str_contains(HighlightViewHelper::highlight($seen, (string)$this->arguments['query']), '<mark>')) {
            return '';
        }
        $highlighted = $resultSet->getUsedSearch()->getHighlightedContent();
        $fragments = $highlighted?->{$document->getId()}->{(string)$this->arguments['fieldName']} ?? null;
        if (!is_array($fragments) || $fragments === []) {
            return '';
        }

        // The first fragment is enough to say why the skill matched.
        $text = trim(preg_replace('/\s+/u', ' ', (string)$fragments[0]) ?? '');
        // Markdown markers (headings, list bullets, quotes, table pipes,
        // code ticks, emphasis) read as noise in one line of text.
        $text = preg_replace('/(^|\s)(#{1,6}|[-*>]|\d+\.)\s+/u', '$1', $text) ?? $text;
        $text = preg_replace('/\s*\|+\s*/u', ' · ', $text) ?? $text;
        $text = str_replace(['`', '**', '__'], '', $text);
        $text = trim(preg_replace('/(?:\s*·\s*){2,}/u', ' · ', $text) ?? $text, " ·\t");
        $escaped = htmlspecialchars(str_replace(['<mark>', '</mark>'], ["\u{E000}", "\u{E001}"], $text), ENT_QUOTES | ENT_HTML5);

        return str_replace(["\u{E000}", "\u{E001}"], ['<mark>', '</mark>'], $escaped);
    }
}
