<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Solr;

use Webconsulting\Skillflow\Support\Typed;

/** Maps nr_llm's materialized SKILL.md fields to Solr dynamic fields. */
final class SkillDocumentFields
{
    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $source
     * @return array<string, string|list<string>>
     */
    public function fromRecord(array $record, array $source = []): array
    {
        $frontmatter = Typed::stringKeyedArray(json_decode(Typed::string($record['raw_frontmatter'] ?? ''), true));
        $metadata = is_array($frontmatter['metadata'] ?? null) ? $frontmatter['metadata'] : [];
        $fields = [];

        foreach ([
            'license' => 'license_stringS',
            'version' => 'version_stringS',
        ] as $key => $field) {
            $value = $this->normalizeScalar($frontmatter[$key] ?? $metadata[$key] ?? null);
            if ($value !== null) {
                $fields[$field] = $key === 'license' ? $this->licenseExpression($value) : $value;
            }
        }

        $category = $this->normalizeScalar($record['tx_skillflow_search_category'] ?? null)
            ?? $this->normalizeScalar($frontmatter['category'] ?? $metadata['category'] ?? null);
        if ($category !== null) {
            $fields['category_stringS'] = $category;
        }

        $tags = $this->normalizeList($record['tx_skillflow_search_tags'] ?? null)
            ?: $this->normalizeList($frontmatter['tags'] ?? $metadata['tags'] ?? null);
        if ($tags !== []) {
            $fields['tags_stringM'] = $tags;
        }

        $allowedTools = $this->normalizeAllowedTools($record['allowed_tools'] ?? null);
        if ($allowedTools !== []) {
            $fields['allowedTools_stringM'] = $allowedTools;
        }

        $sourceUid = $this->normalizeScalar($record['source'] ?? null);
        if ($sourceUid !== null) {
            $fields['sourceUid_stringS'] = $sourceUid;
        }
        foreach (['type' => 'sourceType_stringS', 'title' => 'sourceTitle_stringS'] as $key => $field) {
            $value = $this->normalizeScalar($source[$key] ?? null);
            if ($value !== null) {
                $fields[$field] = $value;
            }
        }

        return $fields;
    }

    private function normalizeScalar(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string)$value);
        return $value !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function normalizeList(mixed $values): array
    {
        if (is_string($values)) {
            $values = str_contains($values, ',') ? explode(',', $values) : [$values];
        }
        if (!is_array($values)) {
            return [];
        }

        $normalized = [];
        foreach ($values as $value) {
            $value = $this->normalizeScalar($value);
            if ($value !== null) {
                $normalized[$value] = $value;
            }
        }

        return array_values($normalized);
    }

    /**
     * The tools a skill may use, by name: "Bash(gh:*)" and "Bash(php:*)" are
     * both Bash. nr_llm splits a rule at its spaces, so
     * "Bash(bash ${CLAUDE_SKILL_DIR}/scripts/*)" arrives as "Bash(bash" and
     * "${CLAUDE_SKILL_DIR}/scripts/*)"; a piece without a tool name is dropped.
     *
     * @return list<string>
     */
    private function normalizeAllowedTools(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        $tools = [];
        foreach ($this->normalizeList(is_array($decoded) ? $decoded : $raw) as $rule) {
            if (preg_match('/^([A-Za-z][A-Za-z0-9_-]*)(?:\(|$)/', $rule, $match) === 1) {
                $tools[$match[1]] = $match[1];
            }
        }

        return array_values($tools);
    }

    /**
     * The licence expression without the note some skills add, so one licence
     * is one facet value: "(MIT AND CC-BY-SA-4.0). See LICENSE-MIT …" and
     * "(MIT AND CC-BY-SA-4.0)" both become "MIT AND CC-BY-SA-4.0". The skill's
     * page keeps the full text.
     */
    private function licenseExpression(string $license): string
    {
        $expression = trim(preg_split('/;\s|\.\s+(?=[A-Z])/', $license, 2)[0] ?? $license, " \t.");
        if (preg_match('/^\(([^()]*)\)$/', $expression, $match) === 1) {
            $expression = trim($match[1]);
        }

        return $expression !== '' ? $expression : $license;
    }
}
