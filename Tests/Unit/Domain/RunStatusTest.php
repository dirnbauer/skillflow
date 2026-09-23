<?php

declare(strict_types=1);

namespace Webconsulting\Skillflow\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use Webconsulting\Skillflow\Domain\RunStatus;
use Webconsulting\Skillflow\Domain\SkillRunResult;

final class RunStatusTest extends TestCase
{
    /** @return iterable<string, array{mixed, RunStatus}> */
    public static function storedValues(): iterable
    {
        yield 'success' => ['success', RunStatus::Success];
        yield 'pending' => ['pending', RunStatus::Pending];
        yield 'unknown value' => ['done', RunStatus::Failed];
        yield 'empty' => ['', RunStatus::Failed];
        yield 'not a string' => [null, RunStatus::Failed];
    }

    #[DataProvider('storedValues')]
    public function testStoredValuesNeverReadAsSuccessByAccident(mixed $value, RunStatus $expected): void
    {
        self::assertSame($expected, RunStatus::fromValue($value));
    }

    public function testOnlyRunningAndPendingAreUnsettled(): void
    {
        $unsettled = array_filter(RunStatus::cases(), static fn(RunStatus $status): bool => !$status->isSettled());

        self::assertSame([RunStatus::Running, RunStatus::Pending], array_values($unsettled));
    }

    public function testSuccessAndPendingAreAccepted(): void
    {
        $accepted = array_filter(RunStatus::cases(), static fn(RunStatus $status): bool => $status->isAccepted());

        self::assertSame([RunStatus::Pending, RunStatus::Success], array_values($accepted));
    }

    public function testSeverityMatchesTheOutcome(): void
    {
        self::assertSame(ContextualFeedbackSeverity::OK, RunStatus::Success->severity());
        self::assertSame(ContextualFeedbackSeverity::WARNING, RunStatus::Blocked->severity());
        self::assertSame(ContextualFeedbackSeverity::ERROR, RunStatus::Failed->severity());
        self::assertSame(ContextualFeedbackSeverity::INFO, RunStatus::Pending->severity());
    }

    public function testResultKeepsItsDataWhenTheRunUidIsAttached(): void
    {
        $result = new SkillRunResult('success', 'Report', 'engine', 'READY', 90, '{}', 'review', 'ref:1', 'https://example.test');
        $settled = $result->withRunUid(12);

        self::assertSame(0, $result->runUid);
        self::assertSame(12, $settled->runUid);
        self::assertEquals($result, new SkillRunResult(...[...get_object_vars($settled), 'runUid' => 0]));
        self::assertTrue($settled->isSuccess());
        self::assertSame(RunStatus::Blocked, SkillRunResult::blocked('Not here')->runStatus());
    }
}
