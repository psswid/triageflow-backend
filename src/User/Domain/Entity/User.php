<?php

declare(strict_types=1);

namespace App\User\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'users')]
final class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'string', length: 180, unique: true)]
    private readonly string $email;

    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(type: 'string')]
    private string $password;

    #[ORM\Column]
    private readonly \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    private ?string $emailVerificationToken = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $verificationTokenExpiresAt = null;

    public function __construct(string $email, string $password)
    {
        $this->id = Uuid::v4();
        $this->email = $email;
        $this->password = $password;
        $this->roles = ['ROLE_USER'];
        $this->createdAt = new \DateTimeImmutable();
        $this->emailVerificationToken = bin2hex(random_bytes(32));
        $this->verificationTokenExpiresAt = new \DateTimeImmutable('+24 hours');
    }

    public static function register(string $email, string $hashedPassword): self
    {
        return new self($email, $hashedPassword);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function getRoles(): array
    {
        return array_unique($this->roles);
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function eraseCredentials(): void
    {
    }

    public function promoteToAdmin(): void
    {
        if (!in_array('ROLE_ADMIN', $this->roles, true)) {
            $this->roles[] = 'ROLE_ADMIN';
        }
    }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->emailVerifiedAt;
    }

    public function getEmailVerificationToken(): ?string
    {
        return $this->emailVerificationToken;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerifiedAt !== null;
    }

    public function markEmailVerified(): void
    {
        if ($this->emailVerifiedAt === null) {
            $this->emailVerifiedAt = new \DateTimeImmutable();
        }
    }

    public function isVerificationTokenExpired(): bool
    {
        return $this->verificationTokenExpiresAt !== null
            && $this->verificationTokenExpiresAt < new \DateTimeImmutable();
    }
}
