<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Domain\Catalog;

/**
 * Facts about one skill collection that its SKILL.md files do not carry:
 * topics, publishers, test coverage and how to install a skill or a whole
 * topic. Read from a catalogue file (Resources/Private/Catalog/*.yaml) and
 * matched to skills by the repository of their nr_llm skill source.
 */
final readonly class SkillCatalog
{
    /**
     * @param array<string, SkillGroup> $groups in display order
     * @param array<string, string> $audiences key => label text or LLL reference, in display order
     * @param array<string, SkillFacts> $skills keyed by skill name
     * @param list<SkillClient> $clients the first one is the default
     */
    public function __construct(
        /** Repository URL without ".git" and trailing slash */
        public string $repository,
        public string $title,
        /** Licence of the collection's own skills; '' when unknown */
        public string $license,
        /** Installs the whole collection, '' when the catalogue names no installer */
        public string $installAll,
        /** One skill: "{skill}" and "{dir}" are replaced */
        public string $installSkill,
        /** A whole group: "{skills}" (space separated) and "{dir}" are replaced */
        public string $installPack,
        public array $groups,
        public array $audiences,
        public array $skills,
        public array $clients,
    ) {}

    public static function normalizeRepository(string $url): string
    {
        $url = rtrim(trim($url), '/');
        if (str_ends_with($url, '.git')) {
            $url = substr($url, 0, -4);
        }

        return strtolower($url);
    }

    public function covers(string $repositoryUrl): bool
    {
        return $repositoryUrl !== '' && self::normalizeRepository($repositoryUrl) === self::normalizeRepository($this->repository);
    }

    public function skill(string $name): ?SkillFacts
    {
        return $this->skills[$name] ?? null;
    }

    public function group(string $key): ?SkillGroup
    {
        return $this->groups[$key] ?? null;
    }

    public function groupOf(string $skill): ?SkillGroup
    {
        $facts = $this->skill($skill);

        return $facts === null ? null : $this->group($facts->group);
    }

    /**
     * The other skills of the skill's group, in the group's order.
     *
     * @return list<string>
     */
    public function relatedSkills(string $skill): array
    {
        $group = $this->groupOf($skill);
        if ($group === null) {
            return [];
        }

        return array_values(array_filter($group->skills, static fn(string $name): bool => $name !== $skill));
    }

    public function client(string $key = ''): ?SkillClient
    {
        foreach ($this->clients as $client) {
            if ($key === '' || $client->key === $key) {
                return $client;
            }
        }

        return null;
    }

    /** The command that installs one skill for the given (default: first) client. */
    public function installSkillCommand(string $skill, string $client = ''): string
    {
        if ($this->installSkill === '') {
            return '';
        }

        return strtr($this->installSkill, ['{skill}' => $skill, '{dir}' => $this->client($client)->directory ?? '']);
    }

    /** The command that installs every skill of a group, '' for an unknown or empty group. */
    public function installPackCommand(string $group, string $client = ''): string
    {
        $skills = $this->group($group)->skills ?? [];
        if ($this->installPack === '' || $skills === []) {
            return '';
        }

        return strtr($this->installPack, ['{skills}' => implode(' ', $skills), '{dir}' => $this->client($client)->directory ?? '']);
    }
}
