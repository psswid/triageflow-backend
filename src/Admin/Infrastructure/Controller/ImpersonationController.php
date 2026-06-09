<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Controller;

use App\User\Domain\Repository\UserRepository;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Admin endpoint to impersonate a user for debugging.
 *
 * Generates a valid JWT token for the target user, allowing
 * admins to log in-as that user and see their view of the system.
 */
final class ImpersonationController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly JWTTokenManagerInterface $jwtManager,
    ) {}

    #[Route('/api/admin/users/{id}/impersonate', methods: ['POST'], name: 'api_admin_impersonate')]
    public function impersonate(string $id): JsonResponse
    {
        $uuid = Uuid::fromString($id);
        $user = $this->userRepository->findById($uuid);

        if ($user === null) {
            throw new NotFoundHttpException(sprintf('User "%s" not found.', $id));
        }

        if (in_array('ROLE_SYSTEM', $user->getRoles(), true)) {
            throw new AccessDeniedHttpException('The system user cannot be impersonated.');
        }

        // Generate a JWT for the target user (same mechanism as login)
        $token = $this->jwtManager->create($user);

        return $this->json([
            'data' => [
                'token' => $token,
                'impersonated' => $user->getEmail(),
            ],
        ]);
    }
}
