<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class JWTSubscriber
{
    #[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_created')]
    public function onJwtCreated(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof \App\User\Domain\Entity\User) {
            return;
        }

        $payload = $event->getData();
        $payload['roles'] = $user->getRoles();
        $event->setData($payload);
    }
}
