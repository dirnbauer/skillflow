<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Domain\Catalog;

/**
 * One topic of a skill catalogue ("Proven site upgrade"): the skills it
 * holds, the words that present it and the page that tells its story.
 */
final readonly class SkillGroup
{
    /**
     * @param list<string> $skills skill names in the order the group presents them
     */
    public function __construct(
        public string $key,
        /** Label text or LLL reference */
        public string $title,
        /** One sentence, label text or LLL reference; '' when none */
        public string $description,
        /** Slug segment of the group's page below the site setting skillflow.search.groupPages; '' when none */
        public string $page,
        /** Position among the groups, 1 for the first */
        public int $position,
        public array $skills,
    ) {}
}
