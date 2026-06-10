<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Controller;

use App\User\Domain\Entity\User;
use App\User\Domain\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly ValidatorInterface $validator,
        private readonly MailerInterface $mailer,
        private readonly string $defaultUri,
    ) {}

    #[Route('/api/register', methods: ['POST'], name: 'api_register')]
    public function __invoke(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $constraints = new Assert\Collection([
            'email' => [
                new Assert\NotBlank(),
                new Assert\Email(),
            ],
            'password' => [
                new Assert\NotBlank(),
                new Assert\Length(['min' => 8]),
            ],
            'password_confirmation' => [
                new Assert\NotBlank(),
            ],
        ]);

        $violations = $this->validator->validate($data, $constraints);
        if (count($violations) > 0) {
            return $this->json([
                'errors' => array_map(fn ($v) => [
                    'status' => '422',
                    'code' => 'VALIDATION_FAILED',
                    'title' => 'Validation Failed',
                    'detail' => $v->getPropertyPath() . ': ' . $v->getMessage(),
                ], iterator_to_array($violations)),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($data['password'] !== $data['password_confirmation']) {
            return $this->json([
                'errors' => [[
                    'status' => '422',
                    'code' => 'PASSWORD_MISMATCH',
                    'title' => 'Password confirmation failed',
                    'detail' => 'password_confirmation: The password confirmation does not match.',
                ]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $existing = $this->userRepository->findByEmail($data['email']);
        if ($existing !== null) {
            return $this->json([
                'errors' => [[
                    'status' => '422',
                    'code' => 'DUPLICATE_EMAIL',
                    'title' => 'Email already registered',
                    'detail' => 'A user with this email already exists.',
                ]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $hashedPassword = $this->passwordHasher->hashPassword(new User('', ''), $data['password']);
        $user = User::register($data['email'], $hashedPassword);
        $this->userRepository->save($user);

        try {
            $email = (new Email())
                ->from('noreply@triageflow.local')
                ->to($user->getEmail())
                ->subject('Verify your TriageFlow account')
                ->html(sprintf(
                    '<a href="%s/verify-email?token=%s">Verify your email</a>',
                    $this->defaultUri,
                    $user->getEmailVerificationToken()
                ));

            $this->mailer->send($email);
        } catch (\Throwable) {
            // Email failure is non-fatal in dev/demo environment
        }

        return $this->json([
            'data' => [
                'id' => $user->getId()->toRfc4122(),
                'type' => 'user',
                'attributes' => [
                    'email' => $user->getEmail(),
                    'roles' => $user->getRoles(),
                    'createdAt' => $user->getCreatedAt()->format('c'),
                    'emailVerified' => $user->isEmailVerified(),
                ],
            ],
        ], Response::HTTP_CREATED);
    }
}
