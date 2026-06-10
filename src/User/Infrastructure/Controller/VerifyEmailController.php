<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Controller;

use App\User\Domain\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VerifyEmailController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {}

    #[Route('/api/verify-email', methods: ['GET'], name: 'api_verify_email')]
    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->query->get('token');

        if (!$token || !is_string($token)) {
            return $this->json(['error' => 'Missing verification token'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->userRepository->findByVerificationToken($token);

        if ($user === null) {
            return $this->json(['error' => 'Invalid verification token'], Response::HTTP_NOT_FOUND);
        }

        if ($user->isEmailVerified()) {
            return $this->json(['message' => 'Email already verified'], Response::HTTP_OK);
        }

        if ($user->isVerificationTokenExpired()) {
            return $this->json(['error' => 'Verification token has expired'], Response::HTTP_GONE);
        }

        $user->markEmailVerified();
        $this->userRepository->save($user);

        return $this->json(['message' => 'Email verified successfully'], Response::HTTP_OK);
    }
}
