<?php

declare(strict_types=1);

namespace App\Tests\User\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class VerifyEmailControllerTest extends WebTestCase
{
    public function testVerifyEmailWithValidToken(): void
    {
        $client = static::createClient();
        $email = 'verify-test-' . \uniqid() . '@example.com';

        $client->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);
        $this->assertResponseStatusCodeSame(201);

        $userRepo = $client->getContainer()->get('App\User\Domain\Repository\UserRepository');
        $user = $userRepo->findByEmail($email);
        $token = $user->getEmailVerificationToken();
        $this->assertNotNull($token);

        $client->jsonRequest('GET', '/api/verify-email?token=' . $token);
        $this->assertResponseStatusCodeSame(200);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('message', $data);
        $this->assertSame('Email verified successfully', $data['message']);
    }

    public function testVerifyEmailWithInvalidToken(): void
    {
        $client = static::createClient();
        $client->jsonRequest('GET', '/api/verify-email?token=invalid-token-that-does-not-exist');
        $this->assertResponseStatusCodeSame(404);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Invalid verification token', $data['error']);
    }

    public function testVerifyEmailWithoutToken(): void
    {
        $client = static::createClient();
        $client->jsonRequest('GET', '/api/verify-email');
        $this->assertResponseStatusCodeSame(400);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Missing verification token', $data['error']);
    }

    public function testVerifyEmailAlreadyVerified(): void
    {
        $client = static::createClient();
        $email = 'verify-already-' . \uniqid() . '@example.com';

        $client->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);
        $this->assertResponseStatusCodeSame(201);

        $userRepo = $client->getContainer()->get('App\User\Domain\Repository\UserRepository');
        $user = $userRepo->findByEmail($email);
        $token = $user->getEmailVerificationToken();

        // First verification
        $client->jsonRequest('GET', '/api/verify-email?token=' . $token);
        $this->assertResponseStatusCodeSame(200);

        // Second verification should say "already verified"
        $client->jsonRequest('GET', '/api/verify-email?token=' . $token);
        $this->assertResponseStatusCodeSame(200);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('Email already verified', $data['message']);
    }
}
