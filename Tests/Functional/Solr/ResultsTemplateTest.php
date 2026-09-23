<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Functional\Solr;

use ApacheSolrForTypo3\Solr\Domain\Search\Query\SearchQuery;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\Result\SearchResult;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\Result\SearchResultCollection;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\SearchResultSet;
use ApacheSolrForTypo3\Solr\Domain\Search\SearchRequest;
use ApacheSolrForTypo3\Solr\Search;
use ApacheSolrForTypo3\Solr\System\Configuration\TypoScriptConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ResultsTemplateTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'scheduler', 'reports', 'tstemplate', 'install'];
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'apache-solr-for-typo3/solr',
        __DIR__ . '/../../..',
    ];

    public function testResultCountAndQueryUseSolrLabelsWithoutSitePackage(): void
    {
        $html = $this->renderResults($this->resultSet('Local review', 1));

        self::assertStringContainsString('Searched for &quot;Local review&quot;.', $html);
        self::assertStringContainsString('Found 1 result in 9 milliseconds.', $html);
        self::assertStringContainsString('class="d-solr-search d-solr-search--skills"', $html);
    }

    public function testMultipleResultsUseCanonicalPaginationRange(): void
    {
        $html = $this->renderResults($this->resultSet('review', 25, 12, 12), 3);

        self::assertStringContainsString('Found 25 results in 9 milliseconds.', $html);
        self::assertStringContainsString('Displaying results 13 to 24 of 25.', $html);
    }

    /** @return iterable<string, array{string}> */
    public static function templateVariants(): iterable
    {
        yield 'catalogue' => ['Solr'];
        yield 'TER layout' => ['SolrTer'];
    }

    #[DataProvider('templateVariants')]
    public function testNoResultsIncludesTheOriginalSearchTerm(string $variant): void
    {
        $html = $this->renderResults($this->resultSet('missing skill', 0), variant: $variant);

        self::assertStringContainsString('Nothing found for &quot;missing skill&quot;.', $html);
    }

    #[DataProvider('templateVariants')]
    public function testCorrectedQueryKeepsBothOriginalAndCorrection(string $variant): void
    {
        $resultSet = $this->resultSet('reviw', 1);
        $resultSet->setIsAutoCorrected(true);
        $resultSet->setInitialQueryString('reviw');
        $resultSet->setCorrectedQueryString('review');
        $html = $this->renderResults($resultSet, variant: $variant);

        self::assertStringContainsString('Nothing found for &quot;reviw&quot;.', $html);
        self::assertStringContainsString('Showing results for &quot;review&quot;.', $html);
    }

    public function testTerHighlightsFirstExactMatchWithoutDuplicatingDocuments(): void
    {
        $resultSet = $this->resultSet('LOCAL REVIEW', 2, 2);
        $resultSet->setSearchResults(new SearchResultCollection([
            new SearchResult(['title' => 'Local review']),
            new SearchResult(['title' => 'Related review']),
        ]));
        $html = $this->renderResults($resultSet, variant: 'SolrTer');

        self::assertSame(1, substr_count($html, '<article data-highlight="1">Local review</article>'));
        self::assertSame(1, substr_count($html, '>Related review</article>'));
    }

    private function resultSet(string $query, int $total, int $shown = 1, int $start = 0): SearchResultSet
    {
        $resultSet = new SearchResultSet();
        $resultSet->setHasSearched(true);
        $resultSet->setUsedQuery(new SearchQuery()->setRawSearchTerm($query));
        $resultSet->setUsedSearchRequest(new SearchRequest(typoScriptConfiguration: new TypoScriptConfiguration([
            'plugin.' => ['tx_solr.' => ['search.' => ['faceting' => 1]]],
        ])));
        $resultSet->setAllResultCount($total);
        $resultSet->setSearchResults(new SearchResultCollection(array_map(
            static fn(int $index): SearchResult => new SearchResult(['title' => 'Review ' . $index]),
            $total > 0 ? range(1, $shown) : [],
        )));
        $search = self::createStub(Search::class);
        $search->method('getQueryTime')->willReturn(9);
        $search->method('getResponseBody')->willReturn((object)['start' => $start]);
        $resultSet->setUsedSearch($search);
        return $resultSet;
    }

    private function renderResults(SearchResultSet $resultSet, int $lastPageNumber = 1, string $variant = 'Solr'): string
    {
        // Isolate messages and layout control flow from form/document rendering.
        // The real Solr labels, range helper and facet partials remain in use.
        $partials = $this->instancePath . '/template-partials';
        foreach (['Search/Form' => 'Form', 'Result/PerPage' => 'PerPage', 'Result/Pagination' => 'Pagination'] as $path => $section) {
            if (!is_dir(dirname($partials . '/' . $path))) {
                mkdir(dirname($partials . '/' . $path), 0777, true);
            }
            file_put_contents($partials . '/' . $path . '.html', '<f:section name="' . $section . '" />');
        }
        file_put_contents(
            $partials . '/Result/Document.html',
            '<f:section name="Document"><article>{document.title}</article></f:section>'
            . '<f:section name="DocumentWrap"><article data-highlight="{exactMatchFound}">{document.title}</article></f:section>',
        );
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            templateRootPaths: ['EXT:skillflow/Resources/Private/' . $variant . '/Templates/'],
            partialRootPaths: ['EXT:solr/Resources/Private/Partials/', $partials],
            layoutRootPaths: ['EXT:solr/Resources/Private/Layouts/'],
        ));
        $view->assignMultiple([
            'resultSet' => $resultSet,
            'pagination' => ['lastPageNumber' => $lastPageNumber, 'startRecordNumber' => 1],
        ]);
        return $view->render('Search/Results');
    }
}
