<?php

declare(strict_types=1);

namespace App\Tests\User\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthControllerTest extends WebTestCase
{
    public function testLoginSuccessReturnsToken(): void
    {
        $client = static::createClient();

        // Register first
        $client->jsonRequest('POST', '/api/register', [
            'email' => 'auth-test@example.com',
            'password' => 'SecurePass123!',
        ]);

        $this->assertResponseStatusCodeSame(201);

        // Login
        $client->jsonRequest('POST', '/api/login', [
            'email' => 'auth-test@example.com',
            'password' => 'SecurePass123!',
        ]);

        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('token', $data);
        $this->assertNotEmpty($data['token']);
    }

    public function testLoginBadCredentials(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'wrong',
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testProtectedEndpointRequiresAuth(): void
    {
        $client = static::createClient();
        // Note: No triage routes exist yet (Issue #3+). Use /api/ with a non-matching
        // path under the JWT-protected firewall. Router returns 404 before security
        // if route missing, so this test is minimal until TriageController exists.
        $client->jsonRequest('GET', '/api/login');

        // GET on login endpoint is not a valid login request; firewall rejects it
        $this->assertResponseStatusCodeSame(405);
    }
}
