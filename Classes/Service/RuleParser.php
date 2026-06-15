<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Service;

use Symfony\Component\Yaml\Yaml;
use Webconsulting\Skillflow\Domain\ParsedSkill;
use Webconsulting\Skillflow\Exception\InvalidSkillException;
use Webconsulting\Skillflow\Support\Typed;

/**
 * Parses a single TYPO3 "agent rule" markdown file: YAML frontmatter
 * delimited by "---" lines (keys: id, title, category, severity,
 * typo3, php, trigger), followed by a markdown body.
 *
 * Maps the rule onto a {@see ParsedSkill} so it can be persisted through
 * the existing skill upsert pipeline with source_type='rules'. All rules
 * share metadata.category='Rules' so they group under a single facet; the
 * rule's own domain stays in metadata.ruleCategory and as a tag.
 *
 * The frontmatter regex and Symfony Yaml::parse mirror {@see SkillParser}.
 */
final class RuleParser
{
    public function parse(string $markdown, string $origin = ''): ParsedSkill
    {
        $frontmatter = [];
        $body = $markdown;

        if (preg_match('/\A---\s*\R(.*?)\R---\s*\R?(.*)\z/s', $markdown, $matches) === 1) {
            try {
                $parsed = Yaml::parse($matches[1]);
            } catch (\Throwable $e) {
                throw new InvalidSkillException(
                    sprintf('Invalid YAML frontmatter in %s: %s', $origin ?: 'rule', $e->getMessage()),
                    1760000003,
                    $e
                );
            }
            $frontmatter = Typed::stringKeyedArray($parsed);
            $body = $matches[2];
        }

        $title = trim(Typed::string($frontmatter['title'] ?? null));
        if ($title === '') {
            throw new InvalidSkillException(
                sprintf('Rule %s misses required frontmatter key "title"', $origin ?: 'rule'),
                1760000004
            );
        }

        $ruleCategory = trim(Typed::string($frontmatter['category'] ?? null));
        $severity = trim(Typed::string($frontmatter['severity'] ?? null));

        $identifier = trim(Typed::string($frontmatter['id'] ?? null));
        if ($identifier === '') {
            $identifier = $this->slugify($title);
        }

        $description = trim(Typed::string($frontmatter['trigger'] ?? null));
        if ($description === '') {
            $description = sprintf(
                '%s rule in %s',
                $severity !== '' ? $severity : 'rule',
                $ruleCategory !== '' ? $ruleCategory : 'general'
            );
        }

        $tags = array_values(array_filter([
            $severity,
            $ruleCategory,
        ], static fn (string $tag): bool => $tag !== ''));

        $metadata = [
            'category' => 'Rules',
            'ruleCategory' => $ruleCategory,
            'severity' => $severity,
            'tags' => $tags,
            'typo3' => Typed::string($frontmatter['typo3'] ?? null),
            'php' => Typed::string($frontmatter['php'] ?? null),
        ];

        return new ParsedSkill(
            identifier: $identifier,
            name: $title,
            description: $description,
            body: trim($body),
            allowedTools: '',
            metadata: $metadata,
        );
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = (string)preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }
}
