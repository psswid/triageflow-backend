<?php

declare(strict_types=1);

namespace App\Tests\User\Infrastructure\Security;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\JWTSubscriber;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use PHPUnit\Framework\TestCase;

final class JWTSubscriberTest extends TestCase
{
    public function testOnJwtCreatedAddsRolesToPayload(): void
    {
        $user = User::register('test@example.com', 'hashed_password');
        $subscriber = new JWTSubscriber();

        // Simulate default payload that Lexik would create
        $defaultPayload = [
            'username' => 'test@example.com',
            'iat' => time(),
            'exp' => time() + 3600,
        ];

        $event = new JWTCreatedEvent($defaultPayload, $user);
        $subscriber->onJwtCreated($event);

        $payload = $event->getData();
        $this->assertArrayHasKey('roles', $payload);
        $this->assertSame(['ROLE_USER'], $payload['roles']);
        $this->assertSame('test@example.com', $payload['username']);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertArrayHasKey('exp', $payload);
    }

    public function testOnJwtCreatedIncludesAdminRole(): void
    {
        $user = User::register('admin@example.com', 'hashed_password');
        $user->promoteToAdmin();
        $subscriber = new JWTSubscriber();

        $defaultPayload = [
            'username' => 'admin@example.com',
            'iat' => time(),
            'exp' => time() + 3600,
        ];

        $event = new JWTCreatedEvent($defaultPayload, $user);
        $subscriber->onJwtCreated($event);

        $payload = $event->getData();
        $this->assertArrayHasKey('roles', $payload);
        $this->assertContains('ROLE_USER', $payload['roles']);
        $this->assertContains('ROLE_ADMIN', $payload['roles']);
        $this->assertCount(2, $payload['roles']);
    }
}
