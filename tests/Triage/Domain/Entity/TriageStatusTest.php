<?php

declare(strict_types=1);

namespace App\Tests\Triage\Domain\Entity;

use App\Triage\Domain\Entity\TriageStatus;
use PHPUnit\Framework\TestCase;

final class TriageStatusTest extends TestCase
{
    public function testEnumHasFiveCases(): void
    {
        $cases = TriageStatus::cases();

        $this->assertCount(5, $cases);
    }

    public function testPendingValue(): void
    {
        $this->assertSame('pending', TriageStatus::Pending->value);
    }

    public function testProcessingValue(): void
    {
        $this->assertSame('processing', TriageStatus::Processing->value);
    }

    public function testAwaitingAnswerValue(): void
    {
        $this->assertSame('awaiting_answer', TriageStatus::AwaitingAnswer->value);
    }

    public function testCompletedValue(): void
    {
        $this->assertSame('completed', TriageStatus::Completed->value);
    }

    public function testFailedValue(): void
    {
        $this->assertSame('failed', TriageStatus::Failed->value);
    }

    public function testTryFromWithValidValues(): void
    {
        $this->assertSame(TriageStatus::Pending, TriageStatus::tryFrom('pending'));
        $this->assertSame(TriageStatus::Processing, TriageStatus::tryFrom('processing'));
        $this->assertSame(TriageStatus::AwaitingAnswer, TriageStatus::tryFrom('awaiting_answer'));
        $this->assertSame(TriageStatus::Completed, TriageStatus::tryFrom('completed'));
        $this->assertSame(TriageStatus::Failed, TriageStatus::tryFrom('failed'));
    }

    public function testTryFromWithInvalidValueReturnsNull(): void
    {
        $this->assertNull(TriageStatus::tryFrom('nonexistent'));
        $this->assertNull(TriageStatus::tryFrom(''));
        $this->assertNull(TriageStatus::tryFrom('PENDING'));
    }

    public function testFromWithInvalidValueThrows(): void
    {
        $this->expectException(\ValueError::class);

        TriageStatus::from('nonexistent');
    }

    public function testEnumIsBackedByString(): void
    {
        $reflection = new \ReflectionEnum(TriageStatus::class);

        $this->assertTrue($reflection->isBacked());
        $this->assertSame('string', (string) $reflection->getBackingType());
    }
}
