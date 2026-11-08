<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\MetaTag\MetaTagManagerRegistry;
use TYPO3\CMS\Core\PageTitle\RecordTitleProvider;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Webconsulting\Skillflow\Domain\Catalog\SkillCatalog;
use Webconsulting\Skillflow\Domain\Catalog\SkillFacts;
use Webconsulting\Skillflow\Service\SkillCatalogRepository;
use Webconsulting\Skillflow\Service\SkillDocumentLinkResolver;
use Webconsulting\Skillflow\Service\SkillFinder;
use Webconsulting\Skillflow\Service\SkillGroupPresenter;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Frontend catalogue detail for an active nr_llm skill: what it does, how to
 * install it (command or prompt) and call it, its SKILL.md, the facts of its
 * catalogue (topic, publisher, licence, evals, last change, source) and the
 * other skills of its topic.
 */
final class SkillDetailController extends ActionController
{
    private const int DESCRIPTION_MAX = 300;

    public function __construct(
        private readonly SkillFinder $skillFinder,
        private readonly RecordTitleProvider $titleProvider,
        private readonly MetaTagManagerRegistry $metaTagManagerRegistry,
        private readonly SkillCatalogRepository $catalogs,
        private readonly SkillGroupPresenter $groupPresenter,
    ) {}

    public function showAction(int $skill = 0): ResponseInterface
    {
        // The route enhancer maps the nr_llm skill name to its record uid.
        $row = $this->skillFinder->findSkillByUid($skill, false);

        if ($row === null) {
            $this->view->assignMultiple(['notFound' => true, 'identifier' => (string)$skill, 'searchUrl' => $this->searchUrl([])]);
            return $this->htmlResponse()->withStatus(404);
        }

        $frontmatter = Typed::stringKeyedArray(json_decode(Typed::string($row['metadata'] ?? ''), true));
        $meta = $frontmatter + Typed::stringKeyedArray($frontmatter['metadata'] ?? null);
        $category = trim(Typed::string($row['tx_skillflow_search_category'] ?? ''));
        if ($category !== '') {
            $meta['category'] = $category;
        }
        $tags = trim(Typed::string($row['tx_skillflow_search_tags'] ?? '')) ?: ($meta['tags'] ?? []);
        $tagValues = is_string($tags) ? explode(',', $tags) : (is_array($tags) ? $tags : []);
        $meta['tags'] = array_values(array_filter(
            array_map(static fn(mixed $tag): string => trim(Typed::string($tag)), $tagValues),
            static fn(string $tag): bool => $tag !== '',
        ));
        foreach (['license', 'version'] as $key) {
            $meta[$key] = trim(Typed::string($meta[$key] ?? ''));
        }
        $skillType = strtolower(trim(Typed::string($meta['skill_type'] ?? '')));
        $meta['skill_type'] = in_array($skillType, ['capability', 'preference'], true) ? $skillType : '';

        $name = Typed::string($row['name'] ?? '');
        $sourceUid = Typed::int($row['source'] ?? 0);
        $source = $this->skillFinder->findSource($sourceUid);
        $site = $this->site();
        $language = $this->language();
        $catalog = $source === null ? null : $this->catalogs->findForRepository($source['url'], $site);
        $facts = $catalog?->skill($name);

        $this->describePage($name, Typed::string($row['description'] ?? ''));
        $this->view->assignMultiple([
            'skill' => $row,
            'meta' => $meta,
            'sourceTitle' => $this->skillFinder->findSourceTitle($sourceUid),
            'allowedTools' => array_values(array_filter(explode(',', Typed::string($row['allowed_tools'] ?? '')), static fn(string $tool): bool => trim($tool) !== '')),
            'abilities' => is_array($row['abilities'] ?? null) ? array_values(array_filter($row['abilities'], is_string(...))) : [],
            'links' => $this->documentLinks($row),
            'facts' => $facts,
            'license' => $this->license($meta['license'], $facts, $catalog),
            'audiences' => $this->audiences($facts, $catalog, $language),
            'updated' => $facts !== null && $facts->updated !== '' ? new \DateTimeImmutable($facts->updated) : null,
            'group' => $this->group($facts, $catalog, $site, $language),
            'related' => $this->related($name, $sourceUid, $catalog),
            'install' => $this->install($name, $catalog, $source['url'] ?? ''),
            'repository' => rtrim(preg_replace('/\.git$/', '', $source['url'] ?? '') ?? '', '/'),
            'sourceUrl' => $this->sourceUrl($row, $source),
            'improveUrl' => $this->improveUrl($name, $facts, $source['url'] ?? ''),
            'searchUrl' => $this->searchUrl([]),
            'topicSearchUrl' => $facts !== null && $facts->group !== '' ? $this->searchUrl(['filter' => ['group:' . $facts->group]]) : '',
        ]);
        return $this->htmlResponse();
    }

    /**
     * Commands and calls, written for the catalogue's first agent; the agent
     * switch on the page rewrites the skills folder for the others.
     *
     * @return array{all: string, skill: string, defaultDirectory: string, clients: list<array{key: string, label: string, directory: string}>, calls: list<array{label: string, call: string}>}
     */
    private function install(string $name, ?SkillCatalog $catalog, string $repositoryUrl): array
    {
        $repository = rtrim(preg_replace('/\.git$/', '', trim($repositoryUrl)) ?? '', '/');
        $skillCommand = $catalog?->installSkillCommand($name) ?? '';
        if ($skillCommand === '' && $repository !== '') {
            // A source without a catalogue: link the folder from a clone.
            $skillCommand = sprintf('git clone %1$s.git ~/%2$s; mkdir -p ~/.claude/skills && ln -s ~/%2$s/skills/%3$s ~/.claude/skills/%3$s', $repository, basename($repository), $name);
        }
        $clients = [];
        $calls = [];
        foreach ($catalog->clients ?? [] as $client) {
            $clients[] = ['key' => $client->key, 'label' => $client->label, 'directory' => $client->directory];
            if ($client->callFor($name) !== '') {
                $calls[] = ['label' => $client->label, 'call' => $client->callFor($name)];
            }
        }

        return [
            'all' => $catalog->installAll ?? '',
            'skill' => $skillCommand,
            'defaultDirectory' => $catalog?->client()->directory ?? '~/.claude/skills',
            'clients' => $clients,
            'calls' => $calls,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function group(?SkillFacts $facts, ?SkillCatalog $catalog, ?Site $site, ?SiteLanguage $language): ?array
    {
        $group = $facts === null ? null : $catalog?->group($facts->group);

        return $group === null || $catalog === null ? null : $this->groupPresenter->present($group, $catalog, $site, $language);
    }

    /**
     * The other skills of the topic that are available here, linked.
     *
     * @return list<array{name: string, description: string, url: string}>
     */
    private function related(string $name, int $sourceUid, ?SkillCatalog $catalog): array
    {
        $related = [];
        foreach ($this->skillFinder->findAvailableSkillsByNames($sourceUid, $catalog?->relatedSkills($name) ?? []) as $skill) {
            $related[] = [
                'name' => $skill['name'],
                'description' => $skill['description'],
                'url' => $this->uriBuilder->reset()->uriFor('show', ['skill' => $skill['uid']]),
            ];
        }

        return $related;
    }

    /** SKILL.md's licence; else the catalogue's, else the collection's for its own skills. */
    private function license(string $declared, ?SkillFacts $facts, ?SkillCatalog $catalog): string
    {
        if ($declared !== '' || $facts === null || $catalog === null) {
            return $declared;
        }

        return $facts->license !== '' ? $facts->license : ($facts->isVendored() ? '' : $catalog->license);
    }

    /**
     * @return list<string>
     */
    private function audiences(?SkillFacts $facts, ?SkillCatalog $catalog, ?SiteLanguage $language): array
    {
        if ($facts === null || $catalog === null) {
            return [];
        }

        return array_values(array_map(
            fn(string $key): string => $this->groupPresenter->translate($catalog->audiences[$key] ?? $key, $language),
            $facts->audience,
        ));
    }

    /**
     * The skill's folder in its repository, at the synchronised revision.
     *
     * @param array<string, mixed> $row
     * @param array{url: string, ref: string, pinnedSha: string}|null $source
     */
    private function sourceUrl(array $row, ?array $source): string
    {
        if ($source === null) {
            return '';
        }
        $revision = trim(Typed::string($row['source_sha'] ?? '')) ?: ($source['pinnedSha'] !== '' ? $source['pinnedSha'] : $source['ref']);
        [$fileBaseUrl] = SkillDocumentLinkResolver::repositoryBaseUrls($source['url'], $revision);
        $folder = dirname(SkillFinder::repositoryPath(Typed::string($row['identifier'] ?? '')));
        if ($fileBaseUrl === '' || $folder === '.' || $folder === '') {
            return '';
        }

        // A folder is a tree, not a blob.
        return preg_replace('#/(-/)?blob/#', '/$1tree/', $fileBaseUrl, 1) . $folder;
    }

    /** Where to suggest an improvement: the collection's issues, upstream's for a vendored skill. */
    private function improveUrl(string $name, ?SkillFacts $facts, string $repositoryUrl): string
    {
        if ($facts !== null && $facts->isVendored() && str_starts_with($facts->upstream, 'https://github.com/')) {
            return rtrim(preg_replace('#/tree/.*$#', '', $facts->upstream) ?? '', '/') . '/issues';
        }
        $repository = rtrim(preg_replace('/\.git$/', '', trim($repositoryUrl)) ?? '', '/');
        if (!str_starts_with($repository, 'https://github.com/')) {
            return '';
        }

        return $repository . '/issues/new?title=' . rawurlencode($name . ': ');
    }

    /**
     * The skills search page of this site (site setting skillflow.search.pages,
     * else skillflow.search.typo3Pages), with Solr arguments; '' when none.
     *
     * @param array<string, mixed> $solrArguments
     */
    private function searchUrl(array $solrArguments): string
    {
        $settings = $this->site()?->getSettings();
        if ($settings === null) {
            return '';
        }
        $pages = GeneralUtility::intExplode(',', Typed::string($settings->get('skillflow.search.pages', '')), true)
            ?: GeneralUtility::intExplode(',', Typed::string($settings->get('skillflow.search.typo3Pages', '')), true);
        if ($pages === []) {
            return '';
        }

        return $this->uriBuilder->reset()
            ->setTargetPageUid($pages[0])
            ->setArguments($solrArguments === [] ? [] : ['tx_solr' => $solrArguments])
            ->buildFrontendUri();
    }

    private function site(): ?Site
    {
        $site = $this->request->getAttribute('site');

        return $site instanceof Site ? $site : null;
    }

    private function language(): ?SiteLanguage
    {
        $language = $this->request->getAttribute('language');

        return $language instanceof SiteLanguage ? $language : null;
    }

    /**
     * Where the SKILL.md's relative links point on this page: other skills to
     * their detail page, any other file to the skill's source repository.
     *
     * @param array<string, mixed> $row
     */
    private function documentLinks(array $row): SkillDocumentLinkResolver
    {
        $sourceUid = Typed::int($row['source'] ?? 0);
        $source = $this->skillFinder->findSource($sourceUid);
        // The revision the body was synchronised from, so every linked file exists as the body describes it.
        $revision = trim(Typed::string($row['source_sha'] ?? ''));
        if ($revision === '') {
            $revision = $source === null ? '' : ($source['pinnedSha'] !== '' ? $source['pinnedSha'] : $source['ref']);
        }
        [$fileBaseUrl, $rawBaseUrl] = SkillDocumentLinkResolver::repositoryBaseUrls($source['url'] ?? '', $revision);
        $skillUids = $this->skillFinder->findAvailableSkillUidsByPath($sourceUid);

        return new SkillDocumentLinkResolver(
            SkillFinder::repositoryPath(Typed::string($row['identifier'] ?? '')),
            $fileBaseUrl,
            $rawBaseUrl,
            fn(string $path): ?string => isset($skillUids[$path])
                ? $this->uriBuilder->reset()->uriFor('show', ['skill' => $skillUids[$path]])
                : null,
        );
    }

    /** The skill, not the detail page, names the document for browsers, bookmarks and search engines. */
    private function describePage(string $name, string $description): void
    {
        if ($name !== '') {
            $this->titleProvider->setTitle($name);
        }
        $description = trim(preg_replace('/\s+/u', ' ', $description) ?? '');
        if ($description !== '') {
            $this->metaTagManagerRegistry
                ->getManagerForProperty('description')
                ->addProperty('description', mb_strimwidth($description, 0, self::DESCRIPTION_MAX, '…'), [], true);
        }
    }
}
