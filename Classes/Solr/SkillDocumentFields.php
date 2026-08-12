<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Solr;

use Symfony\Component\Yaml\Yaml;

/** Maps nr_llm's materialized SKILL.md fields to Solr dynamic fields. */
final class SkillDocumentFields
{
    /**
     * @param array<string, mixed> $record
     * @return array<string, string|list<string>>
     */
    public function fromRecord(array $record): array
    {
        $frontmatter = $this->decodeFrontmatter($record['raw_frontmatter'] ?? null);
        $metadata = is_array($frontmatter['metadata'] ?? null) ? $frontmatter['metadata'] : [];
        $fields = [];

        foreach ([
            'category' => 'category_stringS',
            'license' => 'license_stringS',
            'version' => 'version_stringS',
        ] as $key => $field) {
            $value = $this->normalizeScalar($frontmatter[$key] ?? $metadata[$key] ?? null);
            if ($value !== null) {
                $fields[$field] = $value;
            }
        }

        $tags = $this->normalizeList($frontmatter['tags'] ?? $metadata['tags'] ?? null);
        if ($tags !== []) {
            $fields['tags_stringM'] = $tags;
        }

        $allowedTools = $this->normalizeAllowedTools($record['allowed_tools'] ?? null);
        if ($allowedTools !== []) {
            $fields['allowedTools_stringM'] = $allowedTools;
        }

        $source = $this->normalizeScalar($record['source'] ?? null);
        if ($source !== null) {
            $fields['sourceUid_stringS'] = $source;
        }
        $fields['sourceType_stringS'] = 'nr_llm';

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeFrontmatter(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        try {
            $decoded = Yaml::parse($raw);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
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
