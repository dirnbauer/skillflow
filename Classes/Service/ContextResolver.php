<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
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
final readonly class ContextResolver
{
    public function __construct(private TcaSchemaFactory $schemaFactory) {}

    /**
     * @return array<string, string> token => already-stringified value
     */
    public function resolveTokens(string $table, int $uid, int $workspaceId): array
    {
        $record = BackendUtility::getRecord($table, $uid) ?? [];

        $labelField = $this->schemaFactory->has($table)
            ? $this->schemaFactory->get($table)->getCapability(TcaSchemaCapability::Label)->getPrimaryFieldName()
            : null;

        return [
            '{uid}' => (string)$uid,
            '{table}' => $table,
            '{pid}' => (string)Typed::int($record['pid'] ?? 0),
            '{title}' => Typed::string($record[$labelField ?: 'title'] ?? ''),
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
}
