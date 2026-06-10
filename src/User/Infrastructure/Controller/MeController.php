<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Controller;

use App\User\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class MeController extends AbstractController
{
    #[Route('/api/me', methods: ['GET'], name: 'api_me')]
    public function __invoke(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        return $this->json([
            'data' => [
                'id' => $user->getId()->toRfc4122(),
                'type' => 'user',
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
                'createdAt' => $user->getCreatedAt()->format('c'),
            ],
        ]);
    }
}
