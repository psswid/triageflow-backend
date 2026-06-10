<?php

declare(strict_types=1);

namespace App\Tests\Admin\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminControllerTest extends WebTestCase
{
    protected function tearDown(): void
    {
        \App\Tests\Triage\Infrastructure\Controller\TestTriageAnalyzer::reset();
        parent::tearDown();
    }

    private function uniqueEmail(): string
    {
        return 'admin-test-' . \uniqid() . '@example.com';
    }

    /**
     * Register a user, promote to admin, log in.
     */
    private function createAdminClient(): KernelBrowser
    {
        $client = static::createClient();
        $email = $this->uniqueEmail();
        $password = 'SecurePass123!';

        $client->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Promote the registered user to admin
        $entityManager = $client->getContainer()->get('doctrine.orm.entity_manager');
        $userRepo = $client->getContainer()->get(\App\User\Domain\Repository\UserRepository::class);
        $users = $userRepo->findAll();
        foreach ($users as $user) {
            if ($user->getEmail() === $email) {
                $user->promoteToAdmin();
                $entityManager->flush();
                break;
            }
        }

        // Log in again to get a token with ROLE_ADMIN
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
    // GET /api/admin/stats
    // ─────────────────────────────────────────────────────────────────

    public function testStatsReturns200(): void
    {
        $client = $this->createAdminClient();

        $client->jsonRequest('GET', '/api/admin/stats');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('total', $data['data']);
        $this->assertArrayHasKey('bySpecialist', $data['data']);
        $this->assertArrayHasKey('byUrgency', $data['data']);
    }

    public function testStatsReturns401WithoutAuth(): void
    {
        $client = static::createClient();

        $client->jsonRequest('GET', '/api/admin/stats');

        $this->assertResponseStatusCodeSame(401);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/admin/submissions
    // ─────────────────────────────────────────────────────────────────

    public function testSubmissionsReturns200(): void
    {
        $client = $this->createAdminClient();

        $client->jsonRequest('GET', '/api/admin/submissions');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
    }

    public function testSubmissionsReturns401WithoutAuth(): void
    {
        $client = static::createClient();

        $client->jsonRequest('GET', '/api/admin/submissions');

        $this->assertResponseStatusCodeSame(401);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/admin/submissions/{id}
    // ─────────────────────────────────────────────────────────────────

    public function testSubmissionDetailReturns404ForMissing(): void
    {
        $client = $this->createAdminClient();
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $client->jsonRequest('GET', '/api/admin/submissions/' . $fakeId);

        $this->assertResponseStatusCodeSame(404);
    }

    // ─────────────────────────────────────────────────────────────────
    // GET /api/admin/users
    // ─────────────────────────────────────────────────────────────────

    public function testUsersReturns200(): void
    {
        $client = $this->createAdminClient();

        $client->jsonRequest('GET', '/api/admin/users');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
    }

    public function testUsersReturns401WithoutAuth(): void
    {
        $client = static::createClient();

        $client->jsonRequest('GET', '/api/admin/users');

        $this->assertResponseStatusCodeSame(401);
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/admin/synthetic/generate
    // ─────────────────────────────────────────────────────────────────

    public function testGenerateSyntheticReturns201(): void
    {
        $client = $this->createAdminClient();

        // Ensure system user exists in test DB
        $entityManager = $client->getContainer()->get('doctrine.orm.entity_manager');
        $userRepo = $client->getContainer()->get(\App\User\Domain\Repository\UserRepository::class);

        $systemUser = $userRepo->findById(\Symfony\Component\Uid\Uuid::fromString('00000000-0000-0000-0000-000000000001'));
        if ($systemUser === null) {
            $systemUser = \App\User\Domain\Entity\User::register('system@triageflow.local', '');
            $ref = new \ReflectionProperty($systemUser, 'id');
            $ref->setAccessible(true);
            $ref->setValue($systemUser, \Symfony\Component\Uid\Uuid::fromString('00000000-0000-0000-0000-000000000001'));
            $entityManager->persist($systemUser);
            $entityManager->flush();
        }

        // Configure TestTriageAnalyzer to return a result immediately
        \App\Tests\Triage\Infrastructure\Controller\TestTriageAnalyzer::willReturnResultOnNextCall();

        $client->jsonRequest('POST', '/api/admin/synthetic/generate');

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('id', $data['data']);
        $this->assertTrue($data['data']['attributes']['isSynthetic']);
    }

    public function testGenerateSyntheticReturns401WithoutAuth(): void
    {
        $client = static::createClient();

        $client->jsonRequest('POST', '/api/admin/synthetic/generate');

        $this->assertResponseStatusCodeSame(401);
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/admin/users/{id}/impersonate
    // ─────────────────────────────────────────────────────────────────

    public function testImpersonateReturns200(): void
    {
        $adminClient = $this->createAdminClient();

        // Look up users — find any non-admin user to impersonate
        $adminClient->jsonRequest('GET', '/api/admin/users');
        $this->assertResponseStatusCodeSame(200);
        $users = json_decode($adminClient->getResponse()->getContent(), true)['data'] ?? [];

        $this->assertNotEmpty($users, 'At least one user should exist');
        $targetUser = $users[0];

        $adminClient->jsonRequest('POST', '/api/admin/users/' . $targetUser['id'] . '/impersonate');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($adminClient->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('token', $data['data']);
        $this->assertNotEmpty($data['data']['token']);
        $this->assertSame($targetUser['attributes']['email'], $data['data']['impersonated']);
    }

    public function testImpersonateReturns404ForMissingUser(): void
    {
        $client = $this->createAdminClient();
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $client->jsonRequest('POST', '/api/admin/users/' . $fakeId . '/impersonate');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testImpersonateReturns401WithoutAuth(): void
    {
        $client = static::createClient();
        $fakeId = '00000000-0000-0000-0000-000000000000';

        $client->jsonRequest('POST', '/api/admin/users/' . $fakeId . '/impersonate');

        $this->assertResponseStatusCodeSame(401);
    }
}
