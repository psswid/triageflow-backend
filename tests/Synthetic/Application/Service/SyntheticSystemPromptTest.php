<?php

declare(strict_types=1);

namespace App\Tests\Synthetic\Application\Service;

use App\Synthetic\Application\Service\SyntheticSystemPrompt;
use PHPUnit\Framework\TestCase;

final class SyntheticSystemPromptTest extends TestCase
{
    private SyntheticSystemPrompt $service;

    protected function setUp(): void
    {
        $this->service = new SyntheticSystemPrompt();
    }

    // ─── Symptom Generation Prompt ────────────────────────────────────

    public function testGetSymptomGenerationPromptReturnsNonEmptyString(): void
    {
        $prompt = $this->service->getSymptomGenerationPrompt();

        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
    }

    public function testSymptomPromptContainsPatientSimulationInstructions(): void
    {
        $prompt = $this->service->getSymptomGenerationPrompt();

        $this->assertStringContainsString('simulating a patient', $prompt);
    }

    public function testSymptomPromptContainsAllMedicalDomains(): void
    {
        $prompt = $this->service->getSymptomGenerationPrompt();

        $expectedDomains = [
            'CARDIOLOGY',
            'NEUROLOGY',
            'DERMATOLOGY',
            'ORTHOPEDICS',
            'GASTROENTEROLOGY',
            'PULMONOLOGY',
            'PSYCHIATRY',
        ];

        foreach ($expectedDomains as $domain) {
            $this->assertStringContainsString(
                $domain,
                $prompt,
                sprintf('Symptom generation prompt must mention domain: %s', $domain),
            );
        }
    }

    public function testSymptomPromptContainsDemonstrationDisclaimer(): void
    {
        $prompt = $this->service->getSymptomGenerationPrompt();

        $this->assertStringContainsString('DEMONSTRATION', $prompt);
        $this->assertStringContainsString('synthetic', $prompt);
        $this->assertStringContainsString('All data is synthetic', $prompt);
    }

    public function testSymptomPromptHasCharacterLimit(): void
    {
        $prompt = $this->service->getSymptomGenerationPrompt();

        $this->assertStringContainsString('500', $prompt);
    }

    public function testSymptomPromptUsesFirstPersonLanguage(): void
    {
        $prompt = $this->service->getSymptomGenerationPrompt();

        $this->assertStringContainsString('first-person', $prompt);
    }

    public function testSymptomPromptDoesNotContainRealPatientDataInstructions(): void
    {
        $prompt = $this->service->getSymptomGenerationPrompt();

        $this->assertStringNotContainsString('real patient', $prompt);
    }

    public function testSymptomPromptAsksForRealisticSymptoms(): void
    {
        $prompt = $this->service->getSymptomGenerationPrompt();

        $this->assertStringContainsString('realistic', $prompt);
    }

    // ─── Patient Answer Prompt ────────────────────────────────────────

    public function testGetPatientAnswerPromptReturnsNonEmptyString(): void
    {
        $prompt = $this->service->getPatientAnswerPrompt();

        $this->assertIsString($prompt);
        $this->assertNotEmpty($prompt);
    }

    public function testPatientAnswerPromptContainsPatientSimulationInstructions(): void
    {
        $prompt = $this->service->getPatientAnswerPrompt();

        $this->assertStringContainsString('simulating a patient', $prompt);
    }

    public function testPatientAnswerPromptHasCharacterLimit(): void
    {
        $prompt = $this->service->getPatientAnswerPrompt();

        $this->assertStringContainsString('300', $prompt);
    }

    public function testPatientAnswerPromptContainsDemonstrationDisclaimer(): void
    {
        $prompt = $this->service->getPatientAnswerPrompt();

        $this->assertStringContainsString('DEMONSTRATION', $prompt);
        $this->assertStringContainsString('synthetic', $prompt);
    }

    // ─── Class Structure ──────────────────────────────────────────────

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(SyntheticSystemPrompt::class);

        $this->assertTrue(
            $reflection->isFinal(),
            'SyntheticSystemPrompt must be a final class',
        );
    }

    public function testClassIsReadonly(): void
    {
        $reflection = new \ReflectionClass(SyntheticSystemPrompt::class);

        $this->assertTrue(
            $reflection->isReadOnly(),
            'SyntheticSystemPrompt must be a readonly class',
        );
    }

    public function testFileHasStrictTypes(): void
    {
        $reflection = new \ReflectionClass(SyntheticSystemPrompt::class);
        $filePath = $reflection->getFileName();
        $this->assertIsString($filePath);

        $fileContents = file_get_contents($filePath);
        $this->assertStringContainsString(
            'declare(strict_types=1)',
            (string) $fileContents,
        );
    }
}
