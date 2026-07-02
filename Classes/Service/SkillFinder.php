<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Read access to skills, repositories and run protocol records.
 */
final class SkillFinder
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAllSkills(bool $includeHidden = false): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_skillflow_skill');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        if (!$includeHidden) {
            $queryBuilder->getRestrictions()->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        }
        return $queryBuilder
            ->select('*')
            ->from('tx_skillflow_skill')
            ->orderBy('title')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * All hidden (quarantined or manually disabled) skills — the quarantine
     * screen. Hidden skills never execute; releasing one = unhiding it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findHiddenSkills(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_skillflow_skill');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        return $queryBuilder
            ->select('*')
            ->from('tx_skillflow_skill')
            ->where($queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(1, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->orderBy('title')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findSkillByUid(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_skillflow_skill');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $row = $queryBuilder
            ->select('*')
            ->from('tx_skillflow_skill')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        return $row ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findSkillsByUidList(string $uidList): array
    {
        $uids = GeneralUtility::intExplode(',', $uidList, true);
        if ($uids === []) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_skillflow_skill');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        return $queryBuilder
            ->select('*')
            ->from('tx_skillflow_skill')
            ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, \Doctrine\DBAL\ArrayParameterType::INTEGER)))
            ->orderBy('title')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Skills assigned to a custom workspace stage (sys_workspace_stage record).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findSkillsForStage(int $stageUid, bool $onlyAutoRun = true): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_workspace_stage');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $stage = $queryBuilder
            ->select('uid', 'tx_skillflow_skills', 'tx_skillflow_auto_run')
            ->from('sys_workspace_stage')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($stageUid, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        if ($stage === false || ($onlyAutoRun && !(bool)$stage['tx_skillflow_auto_run'])) {
            return [];
        }
        return $this->findSkillsByUidList(Typed::string($stage['tx_skillflow_skills']));
    }

    /**
     * Minimal page record (uid, title, doktype) for the run target preview.
     *
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
            ->select('uid', 'title', 'doktype', 'tx_skillflow_skills')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        return $row ?: null;
    }

    /**
     * Skills assigned to a page via pages.tx_skillflow_skills.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findSkillsForPage(int $pageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $page = $queryBuilder
            ->select('uid', 'tx_skillflow_skills')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        if ($page === false) {
            return [];
        }
        return $this->findSkillsByUidList(Typed::string($page['tx_skillflow_skills']));
    }

    /**
     * Supporting files (attachments) of a skill, ordered by path.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findFilesForSkill(int $skillUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_skillflow_file');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        return $queryBuilder
            ->select('uid', 'relative_path', 'content', 'size')
            ->from('tx_skillflow_file')
            ->where($queryBuilder->expr()->eq('skill', $queryBuilder->createNamedParameter($skillUid, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->orderBy('relative_path')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAllRepositories(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_skillflow_repository');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        return $queryBuilder
            ->select('*')
            ->from('tx_skillflow_repository')
            ->orderBy('title')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findRepositoryByUid(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_skillflow_repository');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $row = $queryBuilder
            ->select('*')
            ->from('tx_skillflow_repository')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        return $row ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
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
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAssociative();
        return $row ?: null;
    }
}
