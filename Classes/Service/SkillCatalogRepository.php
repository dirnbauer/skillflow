<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillflow\Domain\Catalog\SkillCatalog;
use Webconsulting\Skillflow\Domain\Catalog\SkillClient;
use Webconsulting\Skillflow\Domain\Catalog\SkillFacts;
use Webconsulting\Skillflow\Domain\Catalog\SkillGroup;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Loads the skill catalogues a site uses (site setting skillflow.catalogs,
 * by default the one of github.com/dirnbauer/typo3-skills) and finds the one
 * that describes a skill, by the repository of the skill's source.
 *
 * The file format is documented in Documentation/Search/Catalogue.rst.
 */
final class SkillCatalogRepository
{
    public const string SETTING = 'skillflow.catalogs';
    public const string DEFAULT_CATALOG = 'EXT:skillflow/Resources/Private/Catalog/typo3-skills.yaml';

    /** @var array<string, SkillCatalog|null> parsed files of this request, keyed by path */
    private array $parsed = [];

    public function __construct(
        private readonly SkillFinder $skillFinder,
    ) {}

    /**
     * @return list<SkillCatalog>
     */
    public function catalogsForSite(?Site $site): array
    {
        $files = [self::DEFAULT_CATALOG];
        if ($site !== null && $site->getSettings()->has(self::SETTING)) {
            $configured = $site->getSettings()->get(self::SETTING);
            $files = is_array($configured) ? array_values(array_filter(array_map(Typed::string(...), $configured))) : [];
        }

        $catalogs = [];
        foreach ($files as $file) {
            $catalog = $this->load($file);
            if ($catalog !== null) {
                $catalogs[] = $catalog;
            }
        }

        return $catalogs;
    }

    public function findForRepository(string $repositoryUrl, ?Site $site): ?SkillCatalog
    {
        foreach ($this->catalogsForSite($site) as $catalog) {
            if ($catalog->covers($repositoryUrl)) {
                return $catalog;
            }
        }

        return null;
    }

    /**
     * The catalogue describing an nr_llm skill record, via its source.
     *
     * @param array<string, mixed> $skillRow
     */
    public function findForSkill(array $skillRow, ?Site $site): ?SkillCatalog
    {
        $source = $this->skillFinder->findSource(Typed::int($skillRow['source'] ?? 0));

        return $source === null ? null : $this->findForRepository($source['url'], $site);
    }

    /** Parses one catalogue file; null when it is missing or not a catalogue. */
    public function load(string $file): ?SkillCatalog
    {
        if (array_key_exists($file, $this->parsed)) {
            return $this->parsed[$file];
        }
        $path = GeneralUtility::getFileAbsFileName($file);
        $data = $path !== '' && is_file($path) ? Yaml::parseFile($path) : null;

        return $this->parsed[$file] = is_array($data) ? self::fromArray(Typed::stringKeyedArray($data)) : null;
    }

    /**
     * @param array<string, mixed> $data the parsed catalogue file
     */
    public static function fromArray(array $data): ?SkillCatalog
    {
        $repository = Typed::string($data['repository'] ?? '');
        if ($repository === '') {
            return null;
        }
        $install = Typed::stringKeyedArray($data['install'] ?? null);

        $skillsData = Typed::stringKeyedArray($data['skills'] ?? null);
        $groupsData = Typed::stringKeyedArray($data['groups'] ?? null);

        $members = array_fill_keys(array_keys($groupsData), []);
        foreach ($skillsData as $name => $skill) {
            $group = Typed::string(Typed::stringKeyedArray($skill)['group'] ?? '');
            if (isset($members[$group])) {
                $members[$group][] = $name;
            }
        }

        $groups = [];
        $position = 0;
        foreach ($groupsData as $key => $group) {
            $group = Typed::stringKeyedArray($group);
            $groups[$key] = new SkillGroup(
                key: $key,
                title: Typed::string($group['title'] ?? $key),
                description: Typed::string($group['description'] ?? ''),
                page: Typed::string($group['page'] ?? ''),
                position: ++$position,
                skills: $members[$key],
            );
        }

        $skills = [];
        foreach ($skillsData as $name => $skill) {
            $skill = Typed::stringKeyedArray($skill);
            $evals = Typed::stringKeyedArray($skill['evals'] ?? null);
            $groupKey = Typed::string($skill['group'] ?? '');
            $group = $groups[$groupKey] ?? null;
            $superfeature = (bool)($skill['superfeature'] ?? false);
            $origin = Typed::string($skill['origin'] ?? '');
            $skills[$name] = new SkillFacts(
                name: $name,
                group: $group === null ? '' : $groupKey,
                superfeature: $superfeature,
                audience: self::stringList($skill['audience'] ?? null),
                owner: Typed::string($skill['owner'] ?? ''),
                origin: $origin === SkillFacts::ORIGIN_VENDORED ? SkillFacts::ORIGIN_VENDORED : SkillFacts::ORIGIN_MAINTAINED,
                upstream: Typed::string($skill['upstream'] ?? ''),
                license: Typed::string($skill['license'] ?? ''),
                evals: Typed::int($evals['cases'] ?? 0),
                evalsReviewed: Typed::int($evals['reviewed'] ?? 0),
                updated: self::day($skill['updated'] ?? null),
                // Showcase skills first, then the groups in their order, then the group's own order.
                rank: ($superfeature ? 0 : 10000)
                    + ($group === null ? 9900 : $group->position * 100)
                    + ($group === null ? 0 : (int)array_search($name, $group->skills, true)),
            );
        }

        $clients = [];
        foreach (is_array($data['clients'] ?? null) ? $data['clients'] : [] as $client) {
            $client = Typed::stringKeyedArray($client);
            $key = Typed::string($client['key'] ?? '');
            $directory = Typed::string($client['directory'] ?? '');
            if ($key !== '' && $directory !== '') {
                $clients[] = new SkillClient($key, Typed::string($client['label'] ?? $key), $directory, Typed::string($client['call'] ?? ''));
            }
        }

        $audiences = [];
        foreach (Typed::stringKeyedArray($data['audiences'] ?? null) as $key => $label) {
            $audiences[$key] = Typed::string($label) ?: $key;
        }

        return new SkillCatalog(
            repository: rtrim($repository, '/'),
            title: Typed::string($data['title'] ?? ''),
            license: Typed::string($data['license'] ?? ''),
            installAll: Typed::string($install['all'] ?? ''),
            installSkill: Typed::string($install['skill'] ?? ''),
            installPack: Typed::string($install['pack'] ?? ''),
            groups: $groups,
            audiences: $audiences,
            skills: $skills,
            clients: $clients,
        );
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', Typed::string($value));

        return array_values(array_filter(array_map(static fn(mixed $item): string => trim(Typed::string($item)), $values), static fn(string $item): bool => $item !== ''));
    }

    /** A Y-m-d day; YAML turns an unquoted date into a timestamp. */
    private static function day(mixed $value): string
    {
        if (is_int($value)) {
            return gmdate('Y-m-d', $value);
        }
        $value = trim(Typed::string($value));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }
}
