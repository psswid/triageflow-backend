<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class AuthController extends AbstractController
{
    #[Route('/api/login', methods: ['POST'], name: 'api_login')]
    public function login(): JsonResponse
    {
        // This method is never reached — the json_login firewall intercepts
        // the request before routing. Exists only so the route can be referenced.
        throw new \LogicException('This method should not be reached — json_login firewall handles authentication.');
    }
}
