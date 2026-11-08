<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\ViewHelpers\Catalog;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;
use Webconsulting\Skillflow\Domain\Catalog\SkillCatalog;
use Webconsulting\Skillflow\Service\SkillCatalogRepository;
use Webconsulting\Skillflow\Service\SkillGroupPresenter;

/** Gives the catalogue view helpers the site, language and catalogues of the page. */
abstract class AbstractCatalogViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function __construct(
        protected readonly SkillCatalogRepository $catalogs,
        protected readonly SkillGroupPresenter $groupPresenter,
    ) {}

    protected function site(): ?Site
    {
        $site = $this->request()?->getAttribute('site');

        return $site instanceof Site ? $site : null;
    }

    protected function language(): ?SiteLanguage
    {
        $language = $this->request()?->getAttribute('language');

        return $language instanceof SiteLanguage ? $language : null;
    }

    /**
     * @return list<SkillCatalog>
     */
    protected function siteCatalogs(): array
    {
        return $this->catalogs->catalogsForSite($this->site());
    }

    private function request(): ?ServerRequestInterface
    {
        if ($this->renderingContext === null || !$this->renderingContext->hasAttribute(ServerRequestInterface::class)) {
            return null;
        }
        $request = $this->renderingContext->getAttribute(ServerRequestInterface::class);

        return $request instanceof ServerRequestInterface ? $request : null;
    }
}
