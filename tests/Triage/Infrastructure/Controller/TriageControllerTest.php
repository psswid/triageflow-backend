<?php

declare(strict_types=1);

namespace App\Tests\Triage\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class TriageControllerTest extends WebTestCase
{
    protected function tearDown(): void
    {
        TestTriageAnalyzer::reset();
        parent::tearDown();
    }

    private function uniqueEmail(): string
    {
        return 'triage-test-' . \uniqid() . '@example.com';
    }

    /**
     * Register a user, log in, and return an authenticated KernelBrowser.
     */
    private function createAuthenticatedClient(): KernelBrowser
    {
        $client = static::createClient();
        $email = $this->uniqueEmail();
        $password = 'SecurePass123!';

        $client->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => $password,
        ]);
        $this->assertResponseStatusCodeSame(201);

        $client->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $password,
        ]);
        $this->assertResponseStatusCodeSame(200);
        $loginData = json_decode($client->getResponse()->getContent(), true);

        $client->setServerParameter('HTTP_Authorization', sprintf('Bearer %s', $loginData['token']));

        return $client;
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/triage/submit
    // ─────────────────────────────────────────────────────────────────

    public function testSubmitCreatesSubmissionAndReturns202(): void
    {
        TestTriageAnalyzer::willReturnQuestionOnNextCall();
        $client = $this->createAuthenticatedClient();

        $client->jsonRequest('POST', '/api/triage/submit', [
            'initialDescription' => 'I have a persistent headache for three days.',
        ]);

        $this->assertResponseStatusCodeSame(202);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertSame('triage_submission', $data['data']['type']);
        $this->assertNotEmpty($data['data']['id']);
        $this->assertArrayHasKey('attributes', $data['data']);
    }

    public function testSubmitWithDescriptionExceeding500CharsReturns422(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->jsonRequest('POST', '/api/triage/submit', [
            'initialDescription' => str_repeat('x', 501),
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    public function testSubmitWithoutAuthReturns401(): void
    {
        $client = static::createClient();

        $client->jsonRequest('POST', '/api/triage/submit', [
            'initialDescription' => 'I have a headache.',
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testSubmitWithMissingDescriptionReturns422(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->jsonRequest('POST', '/api/triage/submit', []);

        $this->assertResponseStatusCodeSame(422);
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/triage/{id}/answer
    // ─────────────────────────────────────────────────────────────────

    public function testAnswerOnOwnedSubmissionReturns200(): void
    {
        TestTriageAnalyzer::willReturnQuestionOnNextCall();
        $client = $this->createAuthenticatedClient();

        $client->jsonRequest('POST', '/api/triage/submit', [
            'initialDescription' => 'I have back pain.',
        ]);
        $this->assertResponseStatusCodeSame(202);
        $submitData = json_decode($client->getResponse()->getContent(), true);
        $submissionId = $submitData['data']['id'];

        $client->jsonRequest('POST', '/api/triage/' . $submissionId . '/answer', [
            'content' => 'It hurts in the lower back.',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertSame($submissionId, $data['data']['id']);
    }

    public function testAnswerOnNonOwnedSubmissionReturns403(): void
    {
        TestTriageAnalyzer::willReturnQuestionOnNextCall();
        $clientA = $this->createAuthenticatedClient();

        $clientA->jsonRequest('POST', '/api/triage/submit', [
            'initialDescription' => 'I have a headache.',
        ]);
        $this->assertResponseStatusCodeSame(202);
        $submitData = json_decode($clientA->getResponse()->getContent(), true);
        $submissionId = $submitData['data']['id'];

        self::ensureKernelShutdown();

        $clientB = $this->createAuthenticatedClient();

        $clientB->jsonRequest('POST', '/api/triage/' . $submissionId . '/answer', [
            'content' => 'I think they should see a doctor.',
        ]);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAnswerOnNonExistentSubmissionReturns404(): void
    {
        $client = $this->createAuthenticatedClient();
        $fakeId = Uuid::v4()->toRfc4122();

        $client->jsonRequest('POST', '/api/triage/' . $fakeId . '/answer', [
            'content' => 'Some answer.',
        ]);

        $this->assertResponseStatusCodeSame(404);
    }

    public function testAnswerWithContentExceeding300CharsReturns422(): void
    {
        TestTriageAnalyzer::willReturnQuestionOnNextCall();
        $client = $this->createAuthenticatedClient();

        $client->jsonRequest('POST', '/api/triage/submit', [
            'initialDescription' => 'I have a headache.',
        ]);
        $submitData = json_decode($client->getResponse()->getContent(), true);
        $submissionId = $submitData['data']['id'];

        $client->jsonRequest('POST', '/api/triage/' . $submissionId . '/answer', [
            'content' => str_repeat('x', 301),
        ]);

        $this->assertResponseStatusCodeSame(422);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/triage/status/{id}
    // ─────────────────────────────────────────────────────────────────

    public function testStatusReturns200WithCorrectFields(): void
    {
        TestTriageAnalyzer::willReturnQuestionOnNextCall();
        $client = $this->createAuthenticatedClient();

        $client->jsonRequest('POST', '/api/triage/submit', [
            'initialDescription' => 'I have a headache.',
        ]);
        $submitData = json_decode($client->getResponse()->getContent(), true);
        $submissionId = $submitData['data']['id'];

        $client->jsonRequest('GET', '/api/triage/status/' . $submissionId);

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('status', $data['data']['attributes']);
        $this->assertArrayHasKey('currentTurn', $data['data']['attributes']);
        $this->assertArrayHasKey('lastAssistantMessage', $data['data']['attributes']);
    }

    public function testStatusForNonOwnedReturns403(): void
    {
        TestTriageAnalyzer::willReturnQuestionOnNextCall();
        $clientA = $this->createAuthenticatedClient();

        $clientA->jsonRequest('POST', '/api/triage/submit', [
            'initialDescription' => 'I have a headache.',
        ]);
        $submitData = json_decode($clientA->getResponse()->getContent(), true);
        $submissionId = $submitData['data']['id'];

        self::ensureKernelShutdown();

        $clientB = $this->createAuthenticatedClient();
        $clientB->jsonRequest('GET', '/api/triage/status/' . $submissionId);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testStatusForNonExistentReturns404(): void
    {
        $client = $this->createAuthenticatedClient();
        $fakeId = Uuid::v4()->toRfc4122();

        $client->jsonRequest('GET', '/api/triage/status/' . $fakeId);

        $this->assertResponseStatusCodeSame(404);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/triage/result/{id}
    // ─────────────────────────────────────────────────────────────────

    public function testResultForInProgressSubmissionReturns200WithNullOutcome(): void
    {
        TestTriageAnalyzer::willReturnQuestionOnNextCall();
        $client = $this->createAuthenticatedClient();

        $client->jsonRequest('POST', '/api/triage/submit', [
            'initialDescription' => 'I have a headache.',
        ]);
        $submitData = json_decode($client->getResponse()->getContent(), true);
        $submissionId = $submitData['data']['id'];

        $client->jsonRequest('GET', '/api/triage/result/' . $submissionId);

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertNull($data['data']['attributes']['outcome']);
        $this->assertIsArray($data['data']['attributes']['conversationHistory']);
    }

    public function testResultForCompletedSubmissionReturnsFullPayload(): void
    {
        TestTriageAnalyzer::willReturnResultOnNextCall(
            specialist: 'Cardiologist',
            urgency: 'HIGH',
            justification: 'Chest pain with radiating symptoms suggests cardiac emergency.',
        );
        $client = $this->createAuthenticatedClient();

        $client->jsonRequest('POST', '/api/triage/submit', [
            'initialDescription' => 'I have chest pain radiating to my left arm.',
        ]);
        $submitData = json_decode($client->getResponse()->getContent(), true);
        $submissionId = $submitData['data']['id'];

        $client->jsonRequest('GET', '/api/triage/result/' . $submissionId);

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertNotNull($data['data']['attributes']['outcome']);
        $this->assertSame('Cardiologist', $data['data']['attributes']['outcome']['specialist']);
        $this->assertSame('HIGH', $data['data']['attributes']['outcome']['urgency']);
        $this->assertIsBool($data['data']['attributes']['isSynthetic']);
        $this->assertNotNull($data['data']['attributes']['submittedAt']);
    }

    public function testResultForNonOwnedReturns403(): void
    {
        TestTriageAnalyzer::willReturnQuestionOnNextCall();
        $clientA = $this->createAuthenticatedClient();

        $clientA->jsonRequest('POST', '/api/triage/submit', [
            'initialDescription' => 'I have a headache.',
        ]);
        $submitData = json_decode($clientA->getResponse()->getContent(), true);
        $submissionId = $submitData['data']['id'];

        self::ensureKernelShutdown();

        $clientB = $this->createAuthenticatedClient();
        $clientB->jsonRequest('GET', '/api/triage/result/' . $submissionId);

        $this->assertResponseStatusCodeSame(403);
    }

    public function testResultForNonExistentReturns404(): void
    {
        $client = $this->createAuthenticatedClient();
        $fakeId = Uuid::v4()->toRfc4122();

        $client->jsonRequest('GET', '/api/triage/result/' . $fakeId);

        $this->assertResponseStatusCodeSame(404);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/triage/submissions (deferred)
    // ─────────────────────────────────────────────────────────────────

    public function testSubmissionsReturns200WithEmptyArray(): void
    {
        $client = $this->createAuthenticatedClient();

        $client->jsonRequest('GET', '/api/triage/submissions');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame([], $data['data']);
    }
}
