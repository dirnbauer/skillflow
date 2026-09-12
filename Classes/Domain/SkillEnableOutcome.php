<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Domain;

/**
 * Outcome of SkillAdministrationService::enable(). Mirrors nr_llm's rules:
 * an orphaned skill can never be enabled, an enabled one is left untouched.
 */
enum SkillEnableOutcome: string
{
    case Enabled = 'enabled';
    case AlreadyEnabled = 'already enabled';
    case Orphaned = 'orphaned';
}
