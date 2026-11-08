<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\ViewHelpers\Catalog;

/**
 * The groups (topics) of the skill catalogues of the site, in their order:
 * key, title and description in the page's language, url of the group page
 * ('' when it does not exist), pack (one-line install of all its skills),
 * skills and count.
 *
 * ```
 *   <f:for each="{sf:catalog.groups()}" as="group">{group.title}</f:for>
 * ```
 */
final class GroupsViewHelper extends AbstractCatalogViewHelper
{
    /**
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function render(): array
    {
        $groups = [];
        foreach ($this->siteCatalogs() as $catalog) {
            foreach ($catalog->groups as $key => $group) {
                $groups[$key] ??= $this->groupPresenter->present($group, $catalog, $this->site(), $this->language());
            }
        }

        return array_values($groups);
    }
}
