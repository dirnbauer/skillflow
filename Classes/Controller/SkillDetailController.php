<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Controller;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\MetaTag\MetaTagManagerRegistry;
use TYPO3\CMS\Core\PageTitle\RecordTitleProvider;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use Webconsulting\Skillflow\Service\SkillDocumentLinkResolver;
use Webconsulting\Skillflow\Service\SkillFinder;
use Webconsulting\Skillflow\Support\Typed;

/** Frontend catalogue detail for an active nr_llm skill. */
final class SkillDetailController extends ActionController
{
    private const int DESCRIPTION_MAX = 300;

    public function __construct(
        private readonly SkillFinder $skillFinder,
        private readonly RecordTitleProvider $titleProvider,
        private readonly MetaTagManagerRegistry $metaTagManagerRegistry,
    ) {}

    public function showAction(int $skill = 0): ResponseInterface
    {
        // The route enhancer maps the nr_llm skill name to its record uid.
        $row = $this->skillFinder->findSkillByUid($skill, false);

        if ($row === null) {
            $this->view->assignMultiple(['notFound' => true, 'identifier' => (string)$skill]);
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

        $this->describePage(Typed::string($row['name'] ?? ''), Typed::string($row['description'] ?? ''));
        $this->view->assignMultiple([
            'skill' => $row,
            'meta' => $meta,
            'sourceTitle' => $this->skillFinder->findSourceTitle(Typed::int($row['source'] ?? 0)),
            'allowedTools' => array_values(array_filter(explode(',', Typed::string($row['allowed_tools'] ?? '')), static fn(string $tool): bool => trim($tool) !== '')),
            'abilities' => is_array($row['abilities'] ?? null) ? array_values(array_filter($row['abilities'], is_string(...))) : [],
            'links' => $this->documentLinks($row),
        ]);
        return $this->htmlResponse();
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
