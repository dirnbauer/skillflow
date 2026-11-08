<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Solr;

use Webconsulting\Skillflow\Domain\Catalog\SkillCatalog;
use Webconsulting\Skillflow\Domain\Catalog\SkillFacts;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Maps nr_llm's materialized SKILL.md fields, the search helper fields and a
 * skill catalogue's facts to Solr dynamic fields.
 */
final class SkillDocumentFields
{
    /** The lifecycle classes of SKILL-SPEC.md, front matter metadata.skill_type. */
    private const array SKILL_TYPES = ['capability', 'preference'];

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

        $skillType = strtolower($this->normalizeScalar($metadata['skill_type'] ?? $frontmatter['skill_type'] ?? null) ?? '');
        if (in_array($skillType, self::SKILL_TYPES, true)) {
            $fields['skillType_stringS'] = $skillType;
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

    /**
     * Fields that let the skills search find a skill while its name is being
     * typed: the words of the name as whole-word prefixes from two letters
     * (stringEdgeNgram), and name plus description as word prefixes from
     * three letters (textEdgeNgram). "up" finds typo3-upgrade-run, "acces"
     * finds typo3-accessibility; the stemmed text fields find neither.
     *
     * @param array<string, mixed> $record
     * @return array<string, list<string>>
     */
    public function searchFields(array $record, string $groupTitle = ''): array
    {
        $name = $this->normalizeScalar($record['name'] ?? null) ?? '';
        $words = $name === '' ? [] : array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($name)) ?: [],
            static fn(string $word): bool => $word !== '',
        )));
        $prefix = array_values(array_filter([
            $name === '' ? null : str_replace(['-', '_'], ' ', $name),
            $this->normalizeScalar($record['description'] ?? null),
            $this->normalizeScalar($groupTitle),
        ], static fn(?string $value): bool => $value !== null));

        $fields = [];
        if ($words !== []) {
            $fields['skillWords_stringEdgeNgramM'] = $words;
        }
        if ($prefix !== []) {
            $fields['skillPrefix_textEdgeNgramM'] = $prefix;
        }

        return $fields;
    }

    /**
     * The catalogue's facts about a skill: topic, showcase flag, audience,
     * publisher, maintenance, test coverage and the day of the last change.
     * A licence is only added when SKILL.md declares none ($declaredLicense).
     *
     * @return array<string, string|list<string>>
     */
    public function catalogFields(SkillFacts $facts, SkillCatalog $catalog, string $groupTitle, string $declaredLicense = ''): array
    {
        $fields = [
            'skillRank_intS' => (string)$facts->rank,
            'skillSuperfeature_intS' => $facts->superfeature ? '1' : '0',
            'skillOrigin_stringS' => $facts->origin,
            'skillEvals_intS' => (string)$facts->evals,
            'skillEvalsReviewed_intS' => (string)$facts->evalsReviewed,
        ];
        if ($facts->group !== '') {
            $fields['skillGroup_stringS'] = $facts->group;
            if ($groupTitle !== '') {
                $fields['skillGroupTitle_stringS'] = $groupTitle;
            }
        }
        if ($facts->audience !== []) {
            $fields['skillAudience_stringM'] = $facts->audience;
        }
        if ($facts->owner !== '') {
            $fields['skillOwner_stringS'] = $facts->owner;
        }
        if ($facts->upstream !== '') {
            $fields['skillUpstream_stringS'] = $facts->upstream;
        }
        if ($facts->updated !== '') {
            $fields['skillUpdated_dateS'] = $facts->updated . 'T00:00:00Z';
        }
        if ($declaredLicense === '') {
            $license = $facts->license !== '' ? $facts->license : ($facts->isVendored() ? '' : $catalog->license);
            if ($license !== '') {
                $fields['license_stringS'] = $this->licenseExpression($license);
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
     * is one facet value: "(MIT AND CC-BY-SA-4.0). See LICENSE-MIT …",
     * "(MIT AND CC-BY-SA-4.0)" and "MIT / CC-BY-SA-4.0" all become
     * "MIT AND CC-BY-SA-4.0". The skill's page keeps the full text.
     */
    public function licenseExpression(string $license): string
    {
        $expression = trim(preg_split('/;\s|\.\s+(?=[A-Z])/', $license, 2)[0] ?? $license, " \t.");
        if (preg_match('/^\(([^()]*)\)$/', $expression, $match) === 1) {
            $expression = trim($match[1]);
        }
        // "MIT / CC-BY-SA-4.0" is the split licensing that SPDX writes
        // "MIT AND CC-BY-SA-4.0" (code MIT, content CC BY-SA): one facet value.
        $expression = preg_replace('/\s+\/\s+/', ' AND ', $expression) ?? $expression;

        return $expression !== '' ? $expression : $license;
    }
}
