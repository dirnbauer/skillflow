<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
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
final readonly class SkillFinder
{
    public const string ASSIGNMENT_FIELD = 'tx_skillflow_nrllm_skills';
    private const string SKILL_TABLE = 'tx_nrllm_skill';
    private const string RUN_TABLE = 'tx_skillflow_run';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

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
    public function findSkillByUid(int $uid, bool $includeUnavailable = true): ?array
    {
        if ($uid <= 0) {
            return null;
        }

        $queryBuilder = $this->skillQuery($includeUnavailable);
        $row = $queryBuilder
            ->select('*')
            ->from(self::SKILL_TABLE)
            ->andWhere($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $this->normalizeRow($row);
    }

    /** @return array<string, mixed>|null */
    public function findSkillByIdentifier(string $identifier): ?array
    {
        if ($identifier === '') {
            return null;
        }

        $queryBuilder = $this->skillQuery(true);
        $row = $queryBuilder
            ->select('*')
            ->from(self::SKILL_TABLE)
            ->where($queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $this->normalizeRow($row);
    }

    /** Title of an nr_llm skill source, '' when it does not exist (any more). */
    public function findSourceTitle(int $sourceUid): string
    {
        if ($sourceUid <= 0) {
            return '';
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_nrllm_skill_source');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return Typed::string($queryBuilder->select('title')->from('tx_nrllm_skill_source')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($sourceUid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchOne());
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
            ->andWhere($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, ArrayParameterType::INTEGER)))
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
        return $this->findRuns($limit);
    }

    /**
     * Newest runs first, optionally narrowed to one workspace and to one page
     * (runs against the page record itself and against its content elements).
     *
     * @return list<array<string, mixed>>
     */
    public function findRuns(int $limit, ?int $workspaceUid = null, ?int $pageUid = null): array
    {
        $queryBuilder = $this->runQuery();
        $queryBuilder
            ->select('uid', 'crdate', 'skill', 'target_table', 'target_uid', 'workspace_uid', 'stage_uid', 'status', 'runner', 'verdict', 'score', 'external_engine')
            ->from(self::RUN_TABLE)
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults($limit);
        if ($workspaceUid !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('workspace_uid', $queryBuilder->createNamedParameter($workspaceUid, ParameterType::INTEGER)));
        }
        if ($pageUid !== null) {
            $targets = [
                $queryBuilder->expr()->and(
                    $queryBuilder->expr()->eq('target_table', $queryBuilder->createNamedParameter('pages')),
                    $queryBuilder->expr()->eq('target_uid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)),
                ),
            ];
            $contentUids = $this->findContentUidsOnPage($pageUid);
            if ($contentUids !== []) {
                $targets[] = $queryBuilder->expr()->and(
                    $queryBuilder->expr()->eq('target_table', $queryBuilder->createNamedParameter('tt_content')),
                    $queryBuilder->expr()->in('target_uid', $queryBuilder->createNamedParameter($contentUids, ArrayParameterType::INTEGER)),
                );
            }
            $queryBuilder->andWhere($queryBuilder->expr()->or(...$targets));
        }

        return array_values($queryBuilder->executeQuery()->fetchAllAssociative());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRunByUid(int $uid): ?array
    {
        $queryBuilder = $this->runQuery();
        $row = $queryBuilder
            ->select('*')
            ->from(self::RUN_TABLE)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    private function runQuery(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::RUN_TABLE);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return $queryBuilder;
    }

    /**
     * Content elements on a page in every workspace: a run may target a draft.
     *
     * @return list<int>
     */
    private function findContentUidsOnPage(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        return array_map(
            Typed::int(...),
            $queryBuilder->select('uid')->from('tt_content')
                ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, ParameterType::INTEGER)))
                ->executeQuery()
                ->fetchFirstColumn(),
        );
    }

    private function skillQuery(bool $includeUnavailable): QueryBuilder
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
        $row['title'] = Typed::string($row['name'] ?? '');
        $row['public_identifier'] = Typed::string($row['name'] ?? '');
        $row['metadata'] = Typed::string($row['raw_frontmatter'] ?? '');
        $row['source_type'] = 'nr_llm';
        // nr_llm persists JSON; the public runner contract uses a comma-separated list.
        $row['allowed_tools_json'] = Typed::string($row['allowed_tools'] ?? '');
        $allowedTools = json_decode($row['allowed_tools_json'], true);
        $row['allowed_tools'] = is_array($allowedTools)
            ? implode(',', array_filter($allowedTools, is_string(...)))
            : '';
        // SKILL.md "abilities:" (list<string>); see SkillAbilityStore.
        $row['abilities'] = SkillAbilityStore::fromSkillRow($row);

        return $row;
    }
}
