<?php

declare(strict_types=1);

namespace App\Tests\User\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationControllerTest extends WebTestCase
{
    private function uniqueEmail(): string
    {
        return 'reg-test-' . \uniqid() . '@example.com';
    }

    public function testRegistrationSuccess(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail();
        $client->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('id', $data['data']);
        $this->assertSame('user', $data['data']['type']);
        $this->assertSame($email, $data['data']['attributes']['email']);
        $this->assertContains('ROLE_USER', $data['data']['attributes']['roles']);
        $this->assertArrayHasKey('createdAt', $data['data']['attributes']);
        $this->assertFalse($data['data']['attributes']['emailVerified']);
    }

    public function testRegistrationDuplicateEmail(): void
    {
        $client = static::createClient();
        $email = $this->uniqueEmail();

        // First registration succeeds
        $client->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Duplicate registration fails
        $client->jsonRequest('POST', '/api/register', [
            'email' => $email,
            'password' => 'AnotherPass123!',
            'password_confirmation' => 'AnotherPass123!',
        ]);
        $this->assertResponseStatusCodeSame(422);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame('DUPLICATE_EMAIL', $data['errors'][0]['code']);
    }

    public function testRegistrationInvalidEmail(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/register', [
            'email' => 'not-an-email',
            'password' => 'SecurePass123!',
            'password_confirmation' => 'SecurePass123!',
        ]);

        $this->assertResponseStatusCodeSame(422);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame('VALIDATION_FAILED', $data['errors'][0]['code']);
    }

    public function testRegistrationWeakPassword(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/register', [
            'email' => $this->uniqueEmail(),
            'password' => '123',
            'password_confirmation' => '123',
        ]);

        $this->assertResponseStatusCodeSame(422);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame('VALIDATION_FAILED', $data['errors'][0]['code']);
    }

    public function testRegistrationRequiresPasswordConfirmation(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/register', [
            'email' => $this->uniqueEmail(),
            'password' => 'SecurePass123!',
        ]);

        $this->assertResponseStatusCodeSame(422);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame('VALIDATION_FAILED', $data['errors'][0]['code']);
        $this->assertStringContainsString('password_confirmation', $data['errors'][0]['detail']);
    }

    public function testRegistrationPasswordConfirmationMismatch(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/register', [
            'email' => $this->uniqueEmail(),
            'password' => 'SecurePass123!',
            'password_confirmation' => 'DifferentPass456!',
        ]);

        $this->assertResponseStatusCodeSame(422);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame('PASSWORD_MISMATCH', $data['errors'][0]['code']);
    }
}
