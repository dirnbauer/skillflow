<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Domain\Catalog;

/**
 * What a catalogue knows about one skill beyond its SKILL.md: where it
 * belongs, who publishes it and how well it is tested.
 */
final readonly class SkillFacts
{
    public const string ORIGIN_MAINTAINED = 'maintained';
    public const string ORIGIN_VENDORED = 'vendored';

    /**
     * @param list<string> $audience audience keys of the catalogue
     */
    public function __construct(
        public string $name,
        /** Group key, '' for a skill outside every group */
        public string $group,
        /** One of the collection's showcase skills */
        public bool $superfeature,
        public array $audience,
        /** Who publishes the skill ("webconsulting", "Netresearch" …) */
        public string $owner,
        /** maintained: the collection maintains it; vendored: copied unchanged from upstream */
        public string $origin,
        /** Upstream repository of a vendored or adapted skill, '' when the collection is the source */
        public string $upstream,
        /** Licence when SKILL.md declares none, '' when unknown */
        public string $license,
        /** Number of eval cases in evals/evals.json */
        public int $evals,
        /** Eval cases a person reviewed */
        public int $evalsReviewed,
        /** Day of the last change to the skill folder, Y-m-d; '' when unknown */
        public string $updated,
        /** Sort key "superfeatures first": showcase skills, then group order, then the group's own order */
        public int $rank,
    ) {}

    public function isVendored(): bool
    {
        return $this->origin === self::ORIGIN_VENDORED;
    }
}
