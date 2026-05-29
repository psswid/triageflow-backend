<?php

declare(strict_types=1);

namespace App\Tests\User\Infrastructure\Repository;

use App\User\Domain\Entity\User;
use App\User\Infrastructure\Repository\DoctrineUserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineUserRepositoryTest extends KernelTestCase
{
    private DoctrineUserRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(DoctrineUserRepository::class);
    }

    public function testSaveAndFindById(): void
    {
        $user = User::register('findbyid@example.com', '$2y$13$hashedpassword');

        $this->repository->save($user);

        $found = $this->repository->findById($user->getId());
        $this->assertNotNull($found);
        $this->assertSame($user->getId()->toRfc4122(), $found->getId()->toRfc4122());
    }

    public function testFindByEmailReturnsEntity(): void
    {
        $email = 'findbyemail@example.com';
        $user = User::register($email, '$2y$13$hashedpassword');

        $this->repository->save($user);

        $found = $this->repository->findByEmail($email);
        $this->assertNotNull($found);
        $this->assertSame($email, $found->getEmail());
    }

    public function testFindByEmailReturnsNullForNonExistent(): void
    {
        $found = $this->repository->findByEmail('nonexistent@example.com');
        $this->assertNull($found);
    }

    public function testFindByIdReturnsNullForNonExistent(): void
    {
        $found = $this->repository->findById(\Symfony\Component\Uid\Uuid::v4());
        $this->assertNull($found);
    }
}
