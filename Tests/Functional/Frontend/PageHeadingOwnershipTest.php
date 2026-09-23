<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Functional\Frontend;

use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The skill detail view renders the page's h1 itself. ext_localconf.php adds an
 * entry to lib.pageHeadingOwnedByContent so a theme that reads the registry
 * drops its own page-title h1 on exactly the pages holding the plugin.
 */
final class PageHeadingOwnershipTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['workspaces', 'scheduler', 'reports', 'tstemplate', 'install'];
    protected array $testExtensionsToLoad = [
        'netresearch/nr-vault',
        'netresearch/nr-llm',
        'apache-solr-for-typo3/solr',
        'webconsulting/skillflow',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'is_siteroot' => 1, 'doktype' => 1]);
        $pages->insert('pages', ['uid' => 2, 'pid' => 1, 'title' => 'Skill', 'slug' => '/skill', 'doktype' => 1]);
        $pages->insert('pages', ['uid' => 3, 'pid' => 1, 'title' => 'About', 'slug' => '/about', 'doktype' => 1]);
        $content = $this->getConnectionPool()->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 1, 'pid' => 2, 'CType' => 'skillflow_skilldetail']);
        $content->insert('tt_content', ['uid' => 2, 'pid' => 3, 'CType' => 'text', 'header' => 'About us']);
        $content->insert('tt_content', ['uid' => 3, 'pid' => 3, 'CType' => 'skillflow_skilldetail', 'hidden' => 1]);
        $this->get(SiteWriter::class)->createNewBasicSite('website', 1, 'https://website.local/');
        $this->setUpFrontendRootPage(1, ['EXT:skillflow/Tests/Functional/Frontend/Fixtures/PageHeadingOwner.typoscript']);
    }

    public function testAPageWithTheSkillDetailPluginOwnsItsHeading(): void
    {
        self::assertSame('content-owns-the-h1|rendered', $this->renderPage(2));
    }

    public function testOtherPagesKeepTheThemeHeading(): void
    {
        // Page 3 only holds a hidden skill detail element.
        self::assertSame('|rendered', $this->renderPage(3));
    }

    private function renderPage(int $pageId): string
    {
        $response = $this->executeFrontendSubRequest(new InternalRequest('https://website.local/')->withPageId($pageId));
        self::assertSame(200, $response->getStatusCode());

        return trim((string)$response->getBody());
    }
}
