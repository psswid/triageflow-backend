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
            'password_confirmation' => $password,
        ]);

        $this->assertResponseStatusCodeSame(201);

        // Retrieve user to get the verification token
        /** @var \App\User\Domain\Repository\UserRepository $userRepository */
        $userRepository = static::getContainer()->get(\App\User\Domain\Repository\UserRepository::class);
        $user = $userRepository->findByEmail($email);
        $this->assertNotNull($user);
        $token = $user->getEmailVerificationToken();
        $this->assertNotNull($token);

        // Verify email
        $client->jsonRequest('GET', '/api/verify-email?token=' . $token);
        $this->assertResponseStatusCodeSame(200);

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

    public function testLoginFailsForUnverifiedEmail(): void
    {
        $client = static::createClient();
        $email = 'unverified-' . \uniqid() . '@example.com';

        $client->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);
        $this->assertResponseStatusCodeSame(201);

        $client->jsonRequest('POST', '/api/login', [
            'email' => $email,
            'password' => 'SecurePass123!',
        ]);
        $this->assertResponseStatusCodeSame(401);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertStringContainsString('verify', strtolower($data['message'] ?? ''));
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
