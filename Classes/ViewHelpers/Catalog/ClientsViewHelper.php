<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\ViewHelpers\Catalog;

/**
 * The coding agents the first skill catalogue of the site names, for the
 * agent switch of the install commands: key, label and skills directory.
 * The first one is the agent the commands are written for.
 *
 * ```
 *   <f:for each="{sf:catalog.clients()}" as="client">{client.label}</f:for>
 * ```
 */
final class ClientsViewHelper extends AbstractCatalogViewHelper
{
    /**
     * @return list<array{key: string, label: string, directory: string}>
     */
    #[\Override]
    public function render(): array
    {
        $catalog = $this->siteCatalogs()[0] ?? null;
        if ($catalog === null) {
            return [];
        }

        return array_map(
            static fn($client): array => ['key' => $client->key, 'label' => $client->label, 'directory' => $client->directory],
            $catalog->clients,
        );
    }
}
