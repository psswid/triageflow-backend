<?php

declare(strict_types=1);

namespace App\Tests\Admin\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AdminControllerTest extends WebTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
    }

    private function uniqueEmail(): string
    {
        return 'admin-test-' . \uniqid() . '@example.com';
    }

    /**
     * Register a user, log in, and promote to admin.
     */
    private function createAdminClient(): KernelBrowser
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

    public function testGenerateSyntheticReturns501(): void
    {
        $client = $this->createAdminClient();

        $client->jsonRequest('POST', '/api/admin/synthetic/generate');

        $this->assertResponseStatusCodeSame(501);
    }
}
