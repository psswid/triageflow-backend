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

    // ─────────────────────────────────────────────────────────────────
    // GET /api/admin/failed-messages
    // ─────────────────────────────────────────────────────────────────

    public function testFailedMessagesReturns200(): void
    {
        $client = $this->createAdminClient();

        $client->jsonRequest('GET', '/api/admin/failed-messages');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertIsArray($data['data']);
        foreach ($data['data'] as $msg) {
            $this->assertArrayHasKey('id', $msg);
            $this->assertSame('failed_message', $msg['type']);
            $this->assertArrayHasKey('attributes', $msg);
            $this->assertArrayHasKey('messageId', $msg['attributes']);
            $this->assertArrayHasKey('type', $msg['attributes']);
            $this->assertArrayHasKey('error', $msg['attributes']);
            $this->assertArrayHasKey('preview', $msg['attributes']);
            $this->assertArrayHasKey('failedAt', $msg['attributes']);
        }
    }

    public function testFailedMessagesReturns401WithoutAuth(): void
    {
        $client = static::createClient();

        $client->jsonRequest('GET', '/api/admin/failed-messages');

        $this->assertResponseStatusCodeSame(401);
    }

    /**
     * Insert a fake failed message into messenger_messages for testing.
     * Returns the inserted row id.
     */
    private function seedFailedMessage(KernelBrowser $client): int
    {
        $conn = $client->getContainer()->get('doctrine.dbal.default_connection');
        $conn->executeStatement(
            "INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at)
             VALUES (:body, :headers, 'failed', NOW(), NOW())",
            [
                'body' => '{"description": "Test patient needs immediate attention for chest pain and shortness of breath"}',
                'headers' => json_encode([
                    'X-Message-Class' => 'App\\Triage\\Application\\Message\\ProcessTriageMessage',
                    'X-Failed-Description' => 'Connection timed out after 5 seconds',
                ]),
            ]
        );

        return (int) $conn->lastInsertId();
    }

    // ─────────────────────────────────────────────────────────────────
    // POST /api/admin/failed-messages/{id}/retry
    // ─────────────────────────────────────────────────────────────────

    public function testRetryFailedMessageReturns200(): void
    {
        $client = $this->createAdminClient();
        $failedId = $this->seedFailedMessage($client);

        $client->jsonRequest('POST', '/api/admin/failed-messages/' . $failedId . '/retry');

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertSame('retried', $data['data']['status']);

        // Verify message was moved back to default queue
        $conn = $client->getContainer()->get('doctrine.dbal.default_connection');
        $row = $conn->fetchAssociative("SELECT queue_name FROM messenger_messages WHERE id = :id", ['id' => $failedId]);
        $this->assertSame('default', $row['queue_name']);
    }

    public function testRetryFailedMessageReturns404ForMissing(): void
    {
        $client = $this->createAdminClient();

        $client->jsonRequest('POST', '/api/admin/failed-messages/999999/retry');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testRetryFailedMessageReturns401WithoutAuth(): void
    {
        $client = static::createClient();

        $client->jsonRequest('POST', '/api/admin/failed-messages/1/retry');

        $this->assertResponseStatusCodeSame(401);
    }

    // ─────────────────────────────────────────────────────────────────
    // DELETE /api/admin/failed-messages/{id}
    // ─────────────────────────────────────────────────────────────────

    public function testDeleteFailedMessageReturns200(): void
    {
        $client = $this->createAdminClient();
        $failedId = $this->seedFailedMessage($client);

        $client->jsonRequest('DELETE', '/api/admin/failed-messages/' . $failedId);

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertSame('deleted', $data['data']['status']);

        // Verify message was deleted
        $conn = $client->getContainer()->get('doctrine.dbal.default_connection');
        $row = $conn->fetchAssociative("SELECT id FROM messenger_messages WHERE id = :id", ['id' => $failedId]);
        $this->assertFalse($row, 'Message should have been deleted');
    }

    public function testDeleteFailedMessageReturns404ForMissing(): void
    {
        $client = $this->createAdminClient();

        $client->jsonRequest('DELETE', '/api/admin/failed-messages/999999');

        $this->assertResponseStatusCodeSame(404);
    }

    public function testDeleteFailedMessageReturns401WithoutAuth(): void
    {
        $client = static::createClient();

        $client->jsonRequest('DELETE', '/api/admin/failed-messages/1');

        $this->assertResponseStatusCodeSame(401);
    }
}
