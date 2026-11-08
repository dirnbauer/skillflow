<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Functional\Solr;

use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\Result\SearchResult;
use ApacheSolrForTypo3\Solr\Domain\Search\ResultSet\SearchResultSet;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Skillflow\Service\SkillCatalogRepository;

/**
 * The parts of the skills search that render without a frontend request:
 * one result row and one topic pack (the form and the facet links need
 * EXT:solr's frontend context and are covered in the lab's browser checks).
 */
final class SkillsSearchPartialsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'scheduler', 'reports', 'tstemplate', 'install'];
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'apache-solr-for-typo3/solr',
        __DIR__ . '/../../..',
    ];

    public function testRowShowsRankNameFactsAndMarksTheQuery(): void
    {
        $html = $this->render('<f:render partial="Skills/Row" arguments="{document: document, resultSet: resultSet, rank: 3, query: \'upg\'}" />', [
            'document' => new SearchResult([
                'id' => 'skill-1',
                'title' => 'typo3-upgrade-run',
                'url' => '/ai/agentic-skills/find/skill/typo3-upgrade-run/',
                'description' => 'Upgrades a <script>site</script> and proves nothing broke.',
                'skillOwner_stringS' => 'webconsulting',
                'sourceTitle_stringS' => 'TYPO3 Skills',
                'skillSuperfeature_intS' => 1,
                'skillGroupTitle_stringS' => 'Proven site upgrade',
                'skillOrigin_stringS' => 'maintained',
                'skillType_stringS' => 'preference',
                'license_stringS' => 'MIT AND CC-BY-SA-4.0',
                'skillEvalsReviewed_intS' => 25,
            ]),
            'resultSet' => new SearchResultSet(),
        ]);

        self::assertStringContainsString('<span class="sf-row__rank" aria-hidden="true">3</span>', $html);
        self::assertStringContainsString('href="/ai/agentic-skills/find/skill/typo3-upgrade-run/" data-skillflow-result-link=""', $html);
        self::assertStringContainsString('typo3-<mark>upg</mark>rade-run', $html);
        self::assertStringContainsString('<mark>Upg</mark>rades a &lt;script&gt;site&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        foreach (['by webconsulting', 'Superfeature', 'Proven site upgrade', 'Maintained here', 'Preference', 'MIT AND CC-BY-SA-4.0', 'Evals: 25 reviewed'] as $text) {
            self::assertStringContainsString($text, $html);
        }
    }

    public function testRowOfAVendoredSkillWithoutFactsStaysQuiet(): void
    {
        $html = $this->render('<f:render partial="Skills/Row" arguments="{document: document, resultSet: resultSet, rank: 1}" />', [
            'document' => new SearchResult(['id' => 'skill-2', 'title' => 'typo3-testing', 'skillOrigin_stringS' => 'vendored']),
            'resultSet' => new SearchResultSet(),
        ]);

        self::assertStringContainsString('Vendored', $html);
        self::assertStringNotContainsString('Superfeature', $html);
        self::assertStringNotContainsString('Evals:', $html);
        self::assertStringNotContainsString('<mark>', $html);
    }

    public function testPackOffersTheOneLineInstallOfItsTopic(): void
    {
        $catalog = $this->get(SkillCatalogRepository::class)->load(SkillCatalogRepository::DEFAULT_CATALOG);
        self::assertNotNull($catalog);
        $html = $this->render('<f:render partial="Skills/Pack" arguments="{group: group}" />', ['group' => [
            'key' => 'accessibility',
            'title' => 'Accessibility',
            'description' => 'WCAG 2.2 AA scans traced to templates.',
            'url' => '/ai/agentic-skills/accessibility/',
            'pack' => $catalog->installPackCommand('accessibility'),
            'skills' => ['typo3-wcag22-aa-agentic', 'typo3-accessibility', 'typo3-a11y'],
            'count' => 3,
            'position' => 8,
        ]]);

        self::assertStringContainsString('aria-labelledby="sf-pack-accessibility-active"', $html);
        self::assertStringContainsString('<h2 class="sf-pack__title" id="sf-pack-accessibility-active">Accessibility</h2>', $html);
        self::assertStringContainsString('for s in typo3-wcag22-aa-agentic typo3-accessibility typo3-a11y; do ln -s ~/typo3-skills/skills/$s ~/.claude/skills/$s; done', html_entity_decode($html));
        self::assertStringContainsString('data-skillflow-copy="sf-pack-accessibility-active-command"', $html);
        self::assertStringContainsString('href="/ai/agentic-skills/accessibility/"', $html);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function render(string $template, array $variables): string
    {
        // One file per template: Fluid caches compiled templates by path and mtime.
        $file = $this->instancePath . '/skills-partial-test-' . md5($template) . '.html';
        file_put_contents($file, '<html xmlns:f="http://typo3.org/ns/TYPO3/CMS/Fluid/ViewHelpers" data-namespace-typo3-fluid="true">' . $template . '</html>');
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            partialRootPaths: ['EXT:skillflow/Resources/Private/Partials/', 'EXT:skillflow/Resources/Private/Solr/Partials/'],
            templatePathAndFilename: $file,
        ));
        $view->assignMultiple($variables);

        return $view->render();
    }
}
