<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use App\User\Domain\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class EmailVerifiedUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        // Skip check for admin users (seeded/created by admin) and system user
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return;
        }

        if (!$user->isEmailVerified()) {
            throw new CustomUserMessageAuthenticationException(
                'Please verify your email address before logging in.'
            );
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        // No post-auth checks needed
    }
}
