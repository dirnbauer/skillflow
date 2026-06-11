<?php

declare(strict_types=1);

namespace Webconsulting\Skills\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\Skills\Support\Typed;

/**
 * Collects the editorial content of a record (and, for pages, its content
 * elements) as plain markdown so a skill runner can review it.
 * Workspace-aware: live records are fetched first and the requested
 * workspace version is overlaid on top.
 */
final class ContentCollector
{
    private const MAX_FIELD_LENGTH = 6000;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function collect(string $table, int $uid, int $workspaceId): string
    {
        $record = BackendUtility::getRecord($table, $uid);
        if ($record === null) {
            throw new \RuntimeException(sprintf('Record %s:%d not found', $table, $uid), 1760000020);
        }
        if ($workspaceId > 0 && BackendUtility::isTableWorkspaceEnabled($table)) {
            BackendUtility::workspaceOL($table, $record, $workspaceId, true);
            if (!is_array($record)) {
                throw new \RuntimeException(sprintf('Record %s:%d is not visible in workspace %d', $table, $uid, $workspaceId), 1760000021);
            }
        }

        $lines = [
            sprintf('# %s record %d (workspace %d)', $table, $uid, $workspaceId),
            '',
            $this->renderFields($table, $record),
        ];

        if ($table === 'pages') {
            $livePageUid = Typed::int($record['t3ver_oid'] ?? 0) ?: Typed::int($record['uid']);
            $lines[] = '';
            $lines[] = '## Content elements on this page';
            $lines[] = $this->renderPageContent($livePageUid, $workspaceId);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<mixed> $record
     */
    private function renderFields(string $table, array $record): string
    {
        $textColumns = [];
        foreach ($this->tcaColumns($table) as $column => $columnConfig) {
            $config = is_array($columnConfig) && is_array($columnConfig['config'] ?? null) ? $columnConfig['config'] : [];
            $type = Typed::string($config['type'] ?? null);
            if (in_array($type, ['input', 'text', 'slug', 'email'], true)) {
                $textColumns[] = $column;
            }
        }

        $lines = [];
        foreach ($textColumns as $column) {
            $value = trim(strip_tags(Typed::string($record[$column] ?? null)));
            if ($value === '') {
                continue;
            }
            if (mb_strlen($value) > self::MAX_FIELD_LENGTH) {
                $value = mb_substr($value, 0, self::MAX_FIELD_LENGTH) . ' …[truncated]';
            }
            $lines[] = sprintf('- **%s**: %s', $column, $value);
        }
        return $lines === [] ? '_(no text content)_' : implode("\n", $lines);
    }

    private function renderPageContent(int $livePageUid, int $workspaceId): string
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(WorkspaceRestriction::class, $workspaceId));
        $rows = $queryBuilder
            ->select('*')
            ->from('tt_content')
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($livePageUid, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        $lines = [];
        foreach ($rows as $row) {
            if ($workspaceId > 0) {
                BackendUtility::workspaceOL('tt_content', $row, $workspaceId, true);
                if (!is_array($row)) {
                    continue;
                }
            }
            if (Typed::int($row['t3ver_state'] ?? 0) === 2) {
                // Delete placeholder: element will be removed when published
                continue;
            }
            $lines[] = sprintf('### Element %d (%s)', Typed::int($row['uid']), Typed::string($row['CType'] ?? null) ?: 'unknown');
            $lines[] = $this->renderFields('tt_content', $row);
            $lines[] = '';
        }
        return $lines === [] ? '_(no content elements)_' : implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function tcaColumns(string $table): array
    {
        $tca = $GLOBALS['TCA'] ?? null;
        if (!is_array($tca) || !is_array($tca[$table] ?? null)) {
            return [];
        }
        return Typed::stringKeyedArray($tca[$table]['columns'] ?? null);
    }
}
