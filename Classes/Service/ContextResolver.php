<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Resolves a small, fixed set of placeholder tokens and substitutes them into
 * skill bodies and per-run instruction text right before a skill is started.
 *
 * Deliberately a closed whitelist applied via a single strtr() pass — there is
 * no expression evaluation and no access to arbitrary record fields — so an
 * edited skill body can never do more than echo these five scalar values of the
 * target record into the prompt. strtr() substitutes simultaneously, so a value
 * that itself contains a brace can never trigger a second resolution pass.
 *
 * Supported tokens: {uid} {table} {pid} {title} {workspace}
 */
final class ContextResolver
{
    /**
     * @return array<string, string> token => already-stringified value
     */
    public function resolveTokens(string $table, int $uid, int $workspaceId): array
    {
        $record = [];
        if ($uid > 0) {
            $fetched = BackendUtility::getRecord($table, $uid);
            if (is_array($fetched)) {
                $record = $fetched;
            }
        }

        $labelField = $this->resolveLabelField($table);

        return [
            '{uid}' => (string)$uid,
            '{table}' => $table,
            '{pid}' => (string)Typed::int($record['pid'] ?? 0),
            '{title}' => Typed::string($record[$labelField] ?? ''),
            '{workspace}' => (string)$workspaceId,
        ];
    }

    /**
     * @param array<string, string> $tokens
     */
    public function apply(string $template, array $tokens): string
    {
        return strtr($template, $tokens);
    }

    private function resolveLabelField(string $table): string
    {
        $tca = $GLOBALS['TCA'] ?? null;
        if (!is_array($tca)) {
            return 'title';
        }
        $tableTca = $tca[$table] ?? null;
        if (!is_array($tableTca)) {
            return 'title';
        }
        $ctrl = $tableTca['ctrl'] ?? null;
        if (!is_array($ctrl)) {
            return 'title';
        }
        $label = $ctrl['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : 'title';
    }
}
