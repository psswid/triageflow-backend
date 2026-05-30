<?php

declare(strict_types=1);

namespace App\Tests\Triage\Domain\Entity;

use App\Triage\Domain\Entity\TriageOutcome;
use PHPUnit\Framework\TestCase;

final class TriageOutcomeTest extends TestCase
{
    // ─── Construction & Factory ─────────────────────────────────────

    public function testCreateSetsAllFields(): void
    {
        $outcome = TriageOutcome::create('CARDIOLOGIST', 'HIGH', 'Patient shows cardiac symptoms');

        $this->assertSame('CARDIOLOGIST', $outcome->getSpecialist());
        $this->assertSame('HIGH', $outcome->getUrgency());
        $this->assertSame('Patient shows cardiac symptoms', $outcome->getJustification());
    }

    public function testCreateWithDifferentValues(): void
    {
        $outcome = TriageOutcome::create('DERMATOLOGIST', 'LOW', 'Mild skin irritation');

        $this->assertSame('DERMATOLOGIST', $outcome->getSpecialist());
        $this->assertSame('LOW', $outcome->getUrgency());
        $this->assertSame('Mild skin irritation', $outcome->getJustification());
    }

    // ─── isComplete ─────────────────────────────────────────────────

    public function testIsCompleteReturnsTrueForCreatedOutcome(): void
    {
        $outcome = TriageOutcome::create('GP', 'MEDIUM', 'General symptoms');

        $this->assertTrue($outcome->isComplete());
    }

    // ─── fromAiResult ───────────────────────────────────────────────

    public function testFromAiResultParsesValidJson(): void
    {
        $data = [
            'type' => 'result',
            'specialist' => 'NEUROLOGIST',
            'urgency' => 'HIGH',
            'justification' => 'Severe headache with neurological symptoms',
        ];

        $outcome = TriageOutcome::fromAiResult($data);

        $this->assertSame('NEUROLOGIST', $outcome->getSpecialist());
        $this->assertSame('HIGH', $outcome->getUrgency());
        $this->assertSame('Severe headache with neurological symptoms', $outcome->getJustification());
        $this->assertTrue($outcome->isComplete());
    }

    public function testFromAiResultWithDifferentSpecialist(): void
    {
        $data = [
            'type' => 'result',
            'specialist' => 'ORTHOPEDIST',
            'urgency' => 'MEDIUM',
            'justification' => 'Joint pain requires specialist review',
        ];

        $outcome = TriageOutcome::fromAiResult($data);

        $this->assertSame('ORTHOPEDIST', $outcome->getSpecialist());
        $this->assertSame('MEDIUM', $outcome->getUrgency());
        $this->assertTrue($outcome->isComplete());
    }

    public function testFromAiResultWithEmergencyUrgency(): void
    {
        $data = [
            'type' => 'result',
            'specialist' => 'CARDIOLOGIST',
            'urgency' => 'EMERGENCY',
            'justification' => 'Chest pain with radiating symptoms',
        ];

        $outcome = TriageOutcome::fromAiResult($data);

        $this->assertSame('EMERGENCY', $outcome->getUrgency());
        $this->assertTrue($outcome->isComplete());
    }

    public function testFromAiResultThrowsOnMissingSpecialist(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TriageOutcome::fromAiResult([
            'type' => 'result',
            'urgency' => 'MEDIUM',
            'justification' => 'Some justification',
        ]);
    }

    public function testFromAiResultThrowsOnMissingUrgency(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TriageOutcome::fromAiResult([
            'type' => 'result',
            'specialist' => 'GP',
            'justification' => 'Some justification',
        ]);
    }

    public function testFromAiResultThrowsOnMissingJustification(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TriageOutcome::fromAiResult([
            'type' => 'result',
            'specialist' => 'GP',
            'urgency' => 'LOW',
        ]);
    }

    public function testFromAiResultAllowsExtraFields(): void
    {
        $data = [
            'type' => 'result',
            'specialist' => 'PSYCHIATRIST',
            'urgency' => 'MEDIUM',
            'justification' => 'Anxiety symptoms',
            'extra_field' => 'should be ignored',
            'another_extra' => 42,
        ];

        $outcome = TriageOutcome::fromAiResult($data);

        $this->assertSame('PSYCHIATRIST', $outcome->getSpecialist());
        $this->assertTrue($outcome->isComplete());
    }

    // ─── Equality / Value Object ────────────────────────────────────

    public function testEqualOutcomesAreEqual(): void
    {
        $a = TriageOutcome::create('GP', 'LOW', 'Mild symptoms');
        $b = TriageOutcome::create('GP', 'LOW', 'Mild symptoms');

        $this->assertTrue($a->equals($b));
        $this->assertTrue($b->equals($a));
    }

    public function testDifferentOutcomesAreNotEqual(): void
    {
        $a = TriageOutcome::create('GP', 'LOW', 'Mild symptoms');
        $b = TriageOutcome::create('CARDIOLOGIST', 'HIGH', 'Serious condition');

        $this->assertFalse($a->equals($b));
    }

    // ─── Value Object Immutability ──────────────────────────────────

    public function testClassIsReadonly(): void
    {
        $reflection = new \ReflectionClass(TriageOutcome::class);

        // readonly class OR readonly properties
        $isReadonlyClass = $reflection->isReadOnly();
        $properties = $reflection->getProperties(\ReflectionProperty::IS_READONLY);

        $this->assertTrue(
            $isReadonlyClass || count($properties) > 0,
            'TriageOutcome must be a readonly class or have readonly properties',
        );
    }
}
