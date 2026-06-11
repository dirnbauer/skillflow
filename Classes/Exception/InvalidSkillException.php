<?php

declare(strict_types=1);

namespace Webconsulting\Skills\Exception;

/**
 * Thrown when a SKILL.md file cannot be parsed or misses required frontmatter.
 */
final class InvalidSkillException extends \RuntimeException
{
}
