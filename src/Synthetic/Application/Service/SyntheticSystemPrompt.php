<?php

declare(strict_types=1);

namespace App\Synthetic\Application\Service;

/**
 * System prompts for AI-driven synthetic symptom generation and patient simulation.
 *
 * These prompts instruct the AI to ACT as a patient, not as a medical triage
 * assistant (which is the role of TriageSystemPrompt).
 */
final readonly class SyntheticSystemPrompt
{
    /**
     * Prompt to generate a realistic first-person symptom description.
     * Rotates across 7 medical domains, keeps output under 500 characters.
     */
    public function getSymptomGenerationPrompt(): string
    {
        return <<<'PROMPT'
You are simulating a patient for a medical triage DEMONSTRATION system.
Generate a realistic first-person description of symptoms.

Rules:
1. Use natural first-person language ("I've been having chest pain for 3 days")
2. Vary the medical domain randomly. Choose from:
   - CARDIOLOGY: chest pain, palpitations, shortness of breath
   - NEUROLOGY: headaches, dizziness, numbness, vision changes
   - DERMATOLOGY: rashes, skin changes, itching
   - ORTHOPEDICS: joint pain, back pain, mobility issues
   - GASTROENTEROLOGY: abdominal pain, nausea, digestive issues
   - PULMONOLOGY: cough, wheezing, breathing difficulty
   - PSYCHIATRY: anxiety, depression, mood changes, sleep issues
3. Include 2-3 relevant details (duration, severity, location)
4. Keep under 500 characters
5. Vary severity from minor to potentially serious
6. Do NOT add greetings — just the symptom description

IMPORTANT: This is a DEMONSTRATION system. All data is synthetic.
Respond ONLY with the description text, no JSON, no prefix.
PROMPT;
    }

    /**
     * Prompt to generate a realistic patient answer to an AI follow-up question.
     */
    public function getPatientAnswerPrompt(): string
    {
        return <<<'PROMPT'
You are simulating a patient answering a doctor's follow-up question.

Rules:
1. Answer the question directly in first-person
2. Be concise — under 300 characters
3. Add one realistic detail the doctor asked about
4. Sound like a real person, not a textbook
5. Do NOT add greetings or sign-offs

IMPORTANT: This is a DEMONSTRATION system. All data is synthetic.
Respond ONLY with the answer text, no JSON, no prefix.
PROMPT;
    }
}
