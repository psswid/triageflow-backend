<?php

declare(strict_types=1);

namespace App\Tests\User\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuthControllerTest extends WebTestCase
{
    private function uniqueEmail(): string
    {
        return 'auth-test-' . \uniqid() . '@example.com';
    }

    public function testLoginSuccessReturnsToken(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail();
        $password = 'SecurePass123!';

        // Register first
        $client->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => $password,
        ]);

        $this->assertResponseStatusCodeSame(201);

        // Login
        $client->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => $password,
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
            'email' => 'nonexistent-' . \uniqid() . '@example.com',
            'password' => 'wrong',
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testProtectedEndpointRequiresAuth(): void
    {
        $client = static::createClient();
        $client->jsonRequest('GET', '/api/login');

        $this->assertResponseStatusCodeSame(405);
    }
}
