<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;
use Webconsulting\Skillflow\Domain\Catalog\SkillCatalog;
use Webconsulting\Skillflow\Domain\Catalog\SkillGroup;
use Webconsulting\Skillflow\Support\Typed;

/**
 * A catalogue group as the frontend shows it: title and description in the
 * page's language, the one-line install of all its skills ("pack") and the
 * URL of its page. The page is found by slug: the site setting
 * skillflow.search.groupPages ("/ai/agentic-skills") plus the group's page
 * segment, so no page uid has to be configured per installation.
 */
final class SkillGroupPresenter
{
    public const string GROUP_PAGES_SETTING = 'skillflow.search.groupPages';

    /** @var array<string, int> page uid by site and slug, 0 = no such page */
    private array $pageUids = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * @return array{key: string, title: string, description: string, url: string, pack: string, skills: list<string>, count: int, position: int}
     */
    public function present(SkillGroup $group, SkillCatalog $catalog, ?Site $site, ?SiteLanguage $language): array
    {
        return [
            'key' => $group->key,
            'title' => $this->translate($group->title, $language),
            'description' => $this->translate($group->description, $language),
            'url' => $site === null ? '' : $this->pageUrl($group, $site, $language),
            'pack' => $catalog->installPackCommand($group->key),
            'skills' => $group->skills,
            'count' => count($group->skills),
            'position' => $group->position,
        ];
    }

    /** A label text or LLL reference in the language of the page. */
    public function translate(string $label, ?SiteLanguage $language): string
    {
        if (!str_starts_with($label, 'LLL:')) {
            return $label;
        }
        $languageService = $language === null
            ? $this->languageServiceFactory->create('default')
            : $this->languageServiceFactory->createFromSiteLanguage($language);

        return $languageService->sL($label);
    }

    public function pageUrl(SkillGroup $group, Site $site, ?SiteLanguage $language): string
    {
        $base = trim(Typed::string($site->getSettings()->get(self::GROUP_PAGES_SETTING, '')), '/');
        if ($base === '' || $group->page === '') {
            return '';
        }
        $uid = $this->pageUid($site, '/' . $base . '/' . trim($group->page, '/'));
        if ($uid === 0) {
            return '';
        }
        try {
            $uri = $site->getRouter()->generateUri($uid, $language === null ? [] : ['_language' => $language]);
        } catch (\Throwable) {
            return '';
        }

        // Same site, same host: a path, like the result links of EXT:solr.
        return (string)$uri->withScheme('')->withHost('')->withPort(null);
    }

    private function pageUid(Site $site, string $slug): int
    {
        $cacheKey = $site->getIdentifier() . $slug;
        if (isset($this->pageUids[$cacheKey])) {
            return $this->pageUids[$cacheKey];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $candidates = $queryBuilder->select('uid')->from('pages')
            ->where(
                $queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter($slug)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
                // Live pages only: a workspace draft has no URL of its own.
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        $uid = 0;
        foreach ($candidates as $candidate) {
            // Several sites may use the same slug; take the page of this one.
            try {
                if ($this->siteFinder->getSiteByPageId(Typed::int($candidate))->getIdentifier() === $site->getIdentifier()) {
                    $uid = Typed::int($candidate);
                    break;
                }
            } catch (\Throwable) {
            }
        }

        return $this->pageUids[$cacheKey] = $uid;
    }
}
