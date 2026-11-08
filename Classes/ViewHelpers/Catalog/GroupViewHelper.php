<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\ViewHelpers\Catalog;

/**
 * One group (topic) of the site's skill catalogues by key, as
 * sf:catalog.groups describes it; null for an unknown key.
 *
 * ```
 *   <f:variable name="group" value="{sf:catalog.group(key: option.value)}" />
 * ```
 */
final class GroupViewHelper extends AbstractCatalogViewHelper
{
    #[\Override]
    public function initializeArguments(): void
    {
        $this->registerArgument('key', 'string', 'Group key, e.g. proven-site-upgrade', true);
    }

    /**
     * @return array<string, mixed>|null
     */
    #[\Override]
    public function render(): ?array
    {
        $key = (string)($this->arguments['key'] ?? '');
        foreach ($this->siteCatalogs() as $catalog) {
            $group = $catalog->group($key);
            if ($group !== null) {
                return $this->groupPresenter->present($group, $catalog, $this->site(), $this->language());
            }
        }

        return null;
    }
}
