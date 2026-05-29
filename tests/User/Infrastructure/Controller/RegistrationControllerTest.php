<?php

declare(strict_types=1);

namespace App\Tests\User\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegistrationControllerTest extends WebTestCase
{
    public function testRegistrationSuccess(): void
    {
        $client = static::createClient();
        $client->jsonRequest('POST', '/api/register', [
            'email' => 'newuser@example.com',
            'password' => 'SecurePass123!',
        ]);

        $this->assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('id', $data['data']);
        $this->assertSame('user', $data['data']['type']);
        $this->assertSame('newuser@example.com', $data['data']['attributes']['email']);
        $this->assertContains('ROLE_USER', $data['data']['attributes']['roles']);
        $this->assertArrayHasKey('createdAt', $data['data']['attributes']);
    }

    public function testRegistrationDuplicateEmail(): void
    {
        $client = static::createClient();

        // First registration succeeds
        $client->jsonRequest('POST', '/api/register', [
            'email' => 'dup@example.com',
            'password' => 'SecurePass123!',
        ]);
        $this->assertResponseStatusCodeSame(201);

        // Duplicate registration fails
        $client->jsonRequest('POST', '/api/register', [
            'email' => 'dup@example.com',
            'password' => 'AnotherPass123!',
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
            'email' => 'test@example.com',
            'password' => '123',
        ]);

        $this->assertResponseStatusCodeSame(422);

        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertSame('VALIDATION_FAILED', $data['errors'][0]['code']);
    }
}
