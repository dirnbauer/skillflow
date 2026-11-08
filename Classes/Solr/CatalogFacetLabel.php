<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Solr;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Core\Attribute\AsAllowedCallable;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;
use Webconsulting\Skillflow\Service\SkillCatalogRepository;
use Webconsulting\Skillflow\Service\SkillGroupPresenter;
use Webconsulting\Skillflow\Support\Typed;

/**
 * renderingInstruction of the skills facets whose values are catalogue keys:
 * the facet option "proven-site-upgrade" reads "Proven site upgrade" in the
 * page's language. EXT:solr renders the instruction with the option value in
 * the content object's data (optionValue).
 *
 *   renderingInstruction = USER
 *   renderingInstruction.userFunc = Webconsulting\Skillflow\Solr\CatalogFacetLabel->render
 *   renderingInstruction.kind = group | audience
 */
#[Autoconfigure(public: true)]
final class CatalogFacetLabel
{
    private ?ContentObjectRenderer $contentObjectRenderer = null;

    public function __construct(
        private readonly SkillCatalogRepository $catalogs,
        private readonly SkillGroupPresenter $groupPresenter,
    ) {}

    public function setContentObjectRenderer(ContentObjectRenderer $contentObjectRenderer): void
    {
        $this->contentObjectRenderer = $contentObjectRenderer;
    }

    /**
     * @param array<string, mixed> $conf
     */
    #[AsAllowedCallable]
    public function render(string $content, array $conf, ServerRequestInterface $request): string
    {
        $value = Typed::string($this->contentObjectRenderer?->data['optionValue'] ?? '');
        $site = $request->getAttribute('site');
        $site = $site instanceof Site ? $site : null;
        $language = $request->getAttribute('language');
        $language = $language instanceof SiteLanguage ? $language : null;

        foreach ($this->catalogs->catalogsForSite($site) as $catalog) {
            $label = match (Typed::string($conf['kind'] ?? 'group')) {
                'audience' => $catalog->audiences[$value] ?? '',
                default => $catalog->group($value)->title ?? '',
            };
            if ($label !== '') {
                return $this->groupPresenter->translate($label, $language);
            }
        }

        return $value;
    }
}
