<?php

declare(strict_types=1);

namespace Webconsulting\Skills\Service;

use Symfony\Component\Yaml\Yaml;
use Webconsulting\Skills\Domain\ParsedSkill;
use Webconsulting\Skills\Exception\InvalidSkillException;
use Webconsulting\Skills\Support\Typed;

/**
 * Parses SKILL.md files (Anthropic skill structure): YAML frontmatter
 * delimited by "---" lines, followed by a markdown body.
 */
final class SkillParser
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
                    sprintf('Invalid YAML frontmatter in %s: %s', $origin ?: 'SKILL.md', $e->getMessage()),
                    1760000001,
                    $e
                );
            }
            $frontmatter = Typed::stringKeyedArray($parsed);
            $body = $matches[2];
        }

        $name = trim(Typed::string($frontmatter['name'] ?? null));
        $description = trim(Typed::string($frontmatter['description'] ?? null));
        if ($name === '' || $description === '') {
            throw new InvalidSkillException(
                sprintf('Skill %s misses required frontmatter keys "name" and/or "description"', $origin ?: 'SKILL.md'),
                1760000002
            );
        }

        $allowedToolsRaw = $frontmatter['allowed-tools'] ?? $frontmatter['allowedTools'] ?? '';
        if (is_array($allowedToolsRaw)) {
            $allowedToolsRaw = implode(',', array_map(Typed::string(...), $allowedToolsRaw));
        }
        $allowedTools = trim(Typed::string($allowedToolsRaw));

        $metadata = $frontmatter;
        unset($metadata['name'], $metadata['description'], $metadata['allowed-tools'], $metadata['allowedTools']);

        return new ParsedSkill(
            identifier: $this->slugify($name),
            name: $name,
            description: $description,
            body: trim($body),
            allowedTools: $allowedTools,
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
