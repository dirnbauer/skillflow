<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillflow\Support\Typed;

/**
 * The abilities contract of a skill: SKILL.md front matter may declare
 *
 *     abilities: [news/list, solr/index-queue]
 *
 * (a YAML list, or one comma/space separated string). nr_llm keeps the whole
 * front matter as JSON in tx_nrllm_skill.raw_frontmatter; skillflow:skills:sync
 * stores the parsed list as JSON in tx_nrllm_skill.tx_skillflow_abilities.
 * An empty column means "never synchronized by skillflow": readers then parse
 * the front matter themselves, so a sync from nr_llm's module is honoured too.
 */
final readonly class SkillAbilityStore
{
    public const string FIELD = 'tx_skillflow_abilities';
    private const string SKILL_TABLE = 'tx_nrllm_skill';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Parse and store the abilities of every skill of one nr_llm source
     * (disabled and orphaned ones included).
     *
     * @return int number of skills that declare at least one ability
     */
    public function refreshSource(int $sourceUid): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::SKILL_TABLE);
        // Connection::select() would apply the default restrictions and skip
        // hidden skills; every non-deleted skill of the source is refreshed.
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $rows = $queryBuilder
            ->select('uid', 'raw_frontmatter', self::FIELD)
            ->from(self::SKILL_TABLE)
            ->where($queryBuilder->expr()->eq('source', $queryBuilder->createNamedParameter($sourceUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAllAssociative();

        $connection = $this->connectionPool->getConnectionForTable(self::SKILL_TABLE);
        $declaring = 0;
        foreach ($rows as $row) {
            $abilities = self::fromFrontmatterJson(Typed::string($row['raw_frontmatter'] ?? '')) ?? [];
            $declaring += $abilities === [] ? 0 : 1;
            $encoded = json_encode($abilities, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if ($encoded !== Typed::string($row[self::FIELD] ?? '')) {
                $connection->update(self::SKILL_TABLE, [self::FIELD => $encoded], ['uid' => Typed::int($row['uid'] ?? 0)]);
            }
        }

        return $declaring;
    }

    /**
     * The abilities of a tx_nrllm_skill row: the stored list, or the front
     * matter when skillflow has not stored one yet.
     *
     * @param array<string, mixed> $row
     * @return list<string>
     */
    public static function fromSkillRow(array $row): array
    {
        $stored = json_decode(Typed::string($row[self::FIELD] ?? ''), true);
        if (is_array($stored)) {
            return self::parseDeclaration($stored);
        }

        return self::fromFrontmatterJson(Typed::string($row['raw_frontmatter'] ?? '')) ?? [];
    }

    /**
     * @return list<string>|null null when the front matter declares no "abilities" key
     */
    public static function fromFrontmatterJson(string $frontmatterJson): ?array
    {
        $frontmatter = json_decode($frontmatterJson, true);
        if (!is_array($frontmatter) || !array_key_exists('abilities', $frontmatter)) {
            return null;
        }

        return self::parseDeclaration($frontmatter['abilities']);
    }

    /**
     * A YAML list of names, or one string of comma- or space-separated names.
     * Names are trimmed and de-duplicated in declaration order; anything that
     * is not a string is ignored. Unknown names stay: skillflow:skills:check
     * reports them as ability_missing.
     *
     * @return list<string>
     */
    public static function parseDeclaration(mixed $declaration): array
    {
        $candidates = match (true) {
            is_string($declaration) => preg_split('/[\s,]+/', $declaration, -1, PREG_SPLIT_NO_EMPTY) ?: [],
            is_array($declaration) => $declaration,
            default => [],
        };

        $names = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $name = trim($candidate);
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
