<?php

declare(strict_types=1);

namespace App\Tests\User\Domain\Entity;

use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    private const string TEST_EMAIL = 'test@example.com';
    private const string TEST_PASSWORD = '$2y$13$hashedpasswordstringhere';

    private User $user;

    protected function setUp(): void
    {
        $this->user = User::register(self::TEST_EMAIL, self::TEST_PASSWORD);
    }

    public function testConstructorSetsEmailAndHashedPassword(): void
    {
        $this->assertSame(self::TEST_EMAIL, $this->user->getEmail());
        $this->assertSame(self::TEST_PASSWORD, $this->user->getPassword());
    }

    public function testDefaultRoleIsUser(): void
    {
        $this->assertSame(['ROLE_USER'], $this->user->getRoles());
    }

    public function testPromoteToAdminAddsAdminRole(): void
    {
        $this->user->promoteToAdmin();

        $this->assertContains('ROLE_ADMIN', $this->user->getRoles());
        $this->assertContains('ROLE_USER', $this->user->getRoles());
    }

    public function testPromoteToAdminIsIdempotent(): void
    {
        $this->user->promoteToAdmin();
        $this->user->promoteToAdmin();

        $roles = $this->user->getRoles();
        // Count occurrences: ROLE_ADMIN should appear exactly once
        $adminCount = count(array_filter($roles, fn(string $role): bool => $role === 'ROLE_ADMIN'));
        $this->assertSame(1, $adminCount, 'ROLE_ADMIN should appear exactly once after multiple promoteToAdmin calls');
    }

    public function testGetUserIdentifierReturnsEmail(): void
    {
        $this->assertSame(self::TEST_EMAIL, $this->user->getUserIdentifier());
    }

    public function testNewUserHasNullEmailVerifiedAt(): void
    {
        $this->assertNull($this->user->getEmailVerifiedAt());
    }

    public function testMarkEmailVerifiedSetsTimestamp(): void
    {
        $this->user->markEmailVerified();
        $this->assertNotNull($this->user->getEmailVerifiedAt());
    }

    public function testGetEmailVerificationTokenReturnsToken(): void
    {
        $token = $this->user->getEmailVerificationToken();
        $this->assertNotNull($token);
        $this->assertNotEmpty($token);
    }

    public function testIsEmailVerifiedReturnsFalseInitially(): void
    {
        $this->assertFalse($this->user->isEmailVerified());
    }

    public function testIsEmailVerifiedReturnsTrueAfterVerification(): void
    {
        $this->user->markEmailVerified();
        $this->assertTrue($this->user->isEmailVerified());
    }

    public function testMarkEmailVerifiedIsIdempotent(): void
    {
        $this->user->markEmailVerified();
        $verifiedAt = $this->user->getEmailVerifiedAt();
        $this->user->markEmailVerified();
        $this->assertSame($verifiedAt, $this->user->getEmailVerifiedAt());
    }
}
