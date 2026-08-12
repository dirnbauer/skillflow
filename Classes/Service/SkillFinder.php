<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Reads nr_llm skills and Skillflow's assignment/run records.
 *
 * nr_llm is the only skill importer and owner. Skillflow stores only its
 * page/user/workspace assignments and run history, all keyed by nr_llm UIDs.
 */
final class SkillFinder
{
    public const ASSIGNMENT_FIELD = 'tx_skillflow_nrllm_skills';
    private const SKILL_TABLE = 'tx_nrllm_skill';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllSkills(bool $includeUnavailable = false): array
    {
        $queryBuilder = $this->skillQuery($includeUnavailable);
        $rows = $queryBuilder
            ->select('*')
            ->from(self::SKILL_TABLE)
            ->orderBy('name')
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->normalizeRows($rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSkillByUid(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }

        $queryBuilder = $this->skillQuery(true);
        $row = $queryBuilder
            ->select('*')
            ->from(self::SKILL_TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $this->normalizeRow($row);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findSkillsByUidList(string $uidList): array
    {
        $uids = GeneralUtility::intExplode(',', $uidList, true);
        if ($uids === []) {
            return [];
        }

        $queryBuilder = $this->skillQuery(false);
        $rows = $queryBuilder
            ->select('*')
            ->from(self::SKILL_TABLE)
            ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, ArrayParameterType::INTEGER)))
            ->orderBy('name')
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->normalizeRows($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findSkillsForStage(int $stageUid, bool $onlyAutoRun = true): array
    {
        return $this->findAssignedSkills(
            'sys_workspace_stage',
            $stageUid,
            $onlyAutoRun ? 'tx_skillflow_auto_run' : null,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findSkillsForPage(int $pageUid): array
    {
        return $this->findAssignedSkills('pages', $pageUid);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findSkillsForBackendUser(int $userUid): array
    {
        return $this->findAssignedSkills('be_users', $userUid);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPageByUid(int $pageUid): ?array
    {
        if ($pageUid <= 0) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $row = $queryBuilder
            ->select('uid', 'title', 'doktype', self::ASSIGNMENT_FIELD)
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findRecentRuns(int $limit = 20): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_skillflow_run');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $queryBuilder
            ->select('*')
            ->from('tx_skillflow_run')
            ->orderBy('crdate', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRunByUid(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_skillflow_run');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $row = $queryBuilder
            ->select('*')
            ->from('tx_skillflow_run')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    private function skillQuery(bool $includeUnavailable): \TYPO3\CMS\Core\Database\Query\QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::SKILL_TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        if (!$includeUnavailable) {
            $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(HiddenRestriction::class));
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq('enabled', $queryBuilder->createNamedParameter(1, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('orphaned', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            );
        }

        return $queryBuilder;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function findAssignedSkills(string $table, int $recordUid, ?string $requiredFlag = null): array
    {
        if ($recordUid <= 0) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $fields = ['uid', self::ASSIGNMENT_FIELD];
        if ($requiredFlag !== null) {
            $fields[] = $requiredFlag;
        }
        $row = $queryBuilder
            ->select(...$fields)
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($recordUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false || ($requiredFlag !== null && !(bool)($row[$requiredFlag] ?? false))) {
            return [];
        }

        return $this->findSkillsByUidList(Typed::string($row[self::ASSIGNMENT_FIELD] ?? ''));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        return array_map($this->normalizeRow(...), $rows);
    }

    /**
     * Keep the long-lived Skillflow runner/engine contract stable while the
     * persisted record is now nr_llm's schema.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $frontmatter = $this->parseFrontmatter(Typed::string($row['raw_frontmatter'] ?? ''));
        $row['title'] = Typed::string($row['name'] ?? '');
        $row['metadata'] = json_encode($frontmatter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $row['source_type'] = 'nr_llm';

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFrontmatter(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }

        try {
            $yaml = Yaml::parse($raw);
            return is_array($yaml) ? $yaml : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
