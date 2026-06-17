<?php

declare(strict_types=1);

namespace App\Tests\Triage\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
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
    private function createAuthenticatedClient(bool $disableReboot = false): KernelBrowser
    {
        $client = static::createClient();

        if ($disableReboot) {
            $client->disableReboot();
        }

        $email = $this->uniqueEmail();
        $password = 'SecurePass123!';

        $client->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Verify email before login
        $userRepo = $client->getContainer()->get(\App\User\Domain\Repository\UserRepository::class);
        $user = $userRepo->findByEmail($email);
        $this->assertNotNull($user);
        $token = $user->getEmailVerificationToken();
        $this->assertNotNull($token);
        $client->jsonRequest('GET', '/api/verify-email?token=' . $token);
        $this->assertResponseStatusCodeSame(200);

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

    // ─────────────────────────────────────────────────────────────────
    // Rate limiter: POST /api/triage/submit
    // ─────────────────────────────────────────────────────────────────

    public function testSubmitReturns429WhenRateLimitExceeded(): void
    {
        TestTriageAnalyzer::willReturnQuestionOnNextCall();
        $client = $this->createAuthenticatedClient(disableReboot: true);

        // Exhaust the 5 tokens
        for ($i = 0; $i < 5; $i++) {
            $client->jsonRequest('POST', '/api/triage/submit', [
                'initialDescription' => 'I have a headache.',
            ]);
            $this->assertResponseStatusCodeSame(202, \sprintf('Submit %d should be accepted within rate limit', $i + 1));
        }

        // 6th request should be rate-limited
        $client->jsonRequest('POST', '/api/triage/submit', [
            'initialDescription' => 'I have a headache.',
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $data = \json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('429', $data['errors'][0]['status']);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $data['errors'][0]['code']);

        $this->assertTrue($client->getResponse()->headers->has('Retry-After'));
        $this->assertIsNumeric($client->getResponse()->headers->get('Retry-After'));
        $this->assertSame('5', $client->getResponse()->headers->get('X-Rate-Limit-Limit'));
        $this->assertSame('0', $client->getResponse()->headers->get('X-Rate-Limit-Remaining'));
    }

    // ─────────────────────────────────────────────────────────────────
    // Rate limiter: POST /api/triage/{id}/answer
    // ─────────────────────────────────────────────────────────────────

    public function testAnswerReturns429WhenRateLimitExceeded(): void
    {
        TestTriageAnalyzer::willReturnQuestionOnNextCall();
        $client = $this->createAuthenticatedClient(disableReboot: true);

        // Create a submission in awaiting_answer status
        $client->jsonRequest('POST', '/api/triage/submit', [
            'initialDescription' => 'I have back pain.',
        ]);
        $this->assertResponseStatusCodeSame(202);
        $submitData = \json_decode($client->getResponse()->getContent(), true);
        $submissionId = $submitData['data']['id'];

        // Exhaust the 5 answer tokens
        // First answer succeeds (200), subsequent ones return 422 (wrong status)
        // but rate limiter token is consumed on every request
        for ($i = 0; $i < 5; $i++) {
            $client->jsonRequest('POST', '/api/triage/' . $submissionId . '/answer', [
                'content' => 'It hurts a lot.',
            ]);

            if ($i === 0) {
                $this->assertResponseStatusCodeSame(200, 'First answer should succeed');
            } else {
                $this->assertResponseStatusCodeSame(422, \sprintf('Answer %d should fail status check but consume token', $i + 1));
            }
        }

        // 6th answer should be rate-limited
        $client->jsonRequest('POST', '/api/triage/' . $submissionId . '/answer', [
            'content' => 'It hurts a lot.',
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);

        $data = \json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('429', $data['errors'][0]['status']);
        $this->assertSame('RATE_LIMIT_EXCEEDED', $data['errors'][0]['code']);

        $this->assertTrue($client->getResponse()->headers->has('Retry-After'));
        $this->assertIsNumeric($client->getResponse()->headers->get('Retry-After'));
        $this->assertSame('5', $client->getResponse()->headers->get('X-Rate-Limit-Limit'));
    }

    // ─────────────────────────────────────────────────────────────────
    // Rate limiter: within limit
    // ─────────────────────────────────────────────────────────────────

    public function testSubmitReturns202WithinRateLimit(): void
    {
        // Token bucket with array cache does not support time manipulation,
        // so we verify that requests within the 5/min limit return 202.
        TestTriageAnalyzer::willReturnQuestionOnNextCall();
        $client = $this->createAuthenticatedClient(disableReboot: true);

        for ($i = 0; $i < 5; $i++) {
            $client->jsonRequest('POST', '/api/triage/submit', [
                'initialDescription' => 'I have a headache.',
            ]);
            $this->assertResponseStatusCodeSame(202, \sprintf('Submit %d should return 202 within limit', $i + 1));
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // Rate limiter: per-user keys
    // ─────────────────────────────────────────────────────────────────

    public function testRateLimitUsesPerUserKeys(): void
    {
        TestTriageAnalyzer::willReturnQuestionOnNextCall();
        $client = $this->createAuthenticatedClient(disableReboot: true);

        // User A exhausts their submit limit
        for ($i = 0; $i < 5; $i++) {
            $client->jsonRequest('POST', '/api/triage/submit', [
                'initialDescription' => 'I have a headache.',
            ]);
            $this->assertResponseStatusCodeSame(202, \sprintf('User A submit %d should succeed', $i + 1));
        }

        // Confirm User A is now rate-limited
        $client->jsonRequest('POST', '/api/triage/submit', [
            'initialDescription' => 'I have a headache.',
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS, 'User A should be rate limited after 5 submits');

        // Now register and authenticate as User B on the same client (same in-memory cache)
        $emailB = $this->uniqueEmail();
        $password = 'SecurePass123!';

        $client->jsonRequest('POST', '/api/register', [
            'email' => $emailB,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Verify email
        $userRepo = $client->getContainer()->get(\App\User\Domain\Repository\UserRepository::class);
        $userB = $userRepo->findByEmail($emailB);
        $this->assertNotNull($userB);
        $token = $userB->getEmailVerificationToken();
        $this->assertNotNull($token);
        $client->jsonRequest('GET', '/api/verify-email?token=' . $token);
        $this->assertResponseStatusCodeSame(200);

        // Login as User B
        $client->jsonRequest('POST', '/api/login', [
            'email' => $emailB,
            'password' => $password,
        ]);
        $this->assertResponseStatusCodeSame(200);
        $loginDataB = \json_decode($client->getResponse()->getContent(), true);
        $client->setServerParameter('HTTP_Authorization', \sprintf('Bearer %s', $loginDataB['token']));

        // User B's first submit should succeed — rate limit is per-user UUID, not global
        $client->jsonRequest('POST', '/api/triage/submit', [
            'initialDescription' => 'I have a headache.',
        ]);
        $this->assertResponseStatusCodeSame(202, 'User B should not be affected by User A\'s rate limit');
    }
}
