<?php

declare(strict_types=1);

namespace App\Tests\User\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MeControllerTest extends WebTestCase
{
    public function testGetMeReturnsAuthenticatedUser(): void
    {
        $client = static::createClient();

        // Register a user first
        $email = 'me-test-' . \uniqid() . '@example.com';
        $client->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
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

        // Login to get token
        $client->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => 'SecurePass123!',
        ]);
        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $token = $data['token'];

        // Call /api/me with token
        $client->jsonRequest('GET', '/api/me', [], [
            'HTTP_Authorization' => 'Bearer ' . $token,
        ]);
        $this->assertResponseStatusCodeSame(200);
        $meData = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('data', $meData);
        $this->assertSame($email, $meData['data']['email']);
        $this->assertSame('user', $meData['data']['type']);
        $this->assertContains('ROLE_USER', $meData['data']['roles']);
    }

    public function testGetMeWithoutTokenReturns401(): void
    {
        $client = static::createClient();
        $client->jsonRequest('GET', '/api/me');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testGetMeWithInvalidTokenReturns401(): void
    {
        $client = static::createClient();
        $client->jsonRequest('GET', '/api/me', [], [
            'HTTP_Authorization' => 'Bearer invalid-token-here',
        ]);
        $this->assertResponseStatusCodeSame(401);
    }
}
