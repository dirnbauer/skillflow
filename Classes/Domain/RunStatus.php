<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Domain;

use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * Lifecycle of a tx_skillflow_run row. The backing values are persisted in
 * tx_skillflow_run.status and returned in SkillRunResult::$status, so they are
 * part of the stored format and of the engine contract.
 */
enum RunStatus: string
{
    /** The row exists and the engine is executing (two-phase persistence). */
    case Running = 'running';
    /** An engine continues asynchronously and settles the row later. */
    case Pending = 'pending';
    case Success = 'success';
    case Failed = 'failed';
    /** Stopped before execution: environment guard, availability, missing credentials, a listener. */
    case Blocked = 'blocked';

    /** Unknown or empty values are reported as failed, never as success. */
    public static function fromValue(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::Failed) : self::Failed;
    }

    /** Whether the run has an outcome that will not change any more. */
    public function isSettled(): bool
    {
        return $this !== self::Running && $this !== self::Pending;
    }

    /** Success, or handed over to an engine that settles it later. */
    public function isAccepted(): bool
    {
        return $this === self::Success || $this === self::Pending;
    }

    public function severity(): ContextualFeedbackSeverity
    {
        return match ($this) {
            self::Success => ContextualFeedbackSeverity::OK,
            self::Running, self::Pending => ContextualFeedbackSeverity::INFO,
            self::Blocked => ContextualFeedbackSeverity::WARNING,
            self::Failed => ContextualFeedbackSeverity::ERROR,
        };
    }
}
