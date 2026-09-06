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
                $fields[$field] = $value;
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
     * @return list<string>
     */
    private function normalizeAllowedTools(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return $this->normalizeList(is_array($decoded) ? $decoded : $raw);
    }
}
