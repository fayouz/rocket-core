<?php

namespace Rocket\Core\Controller;

use Rocket\Core\Dashboard\PlatformHealth;
use Rocket\Core\Health\HealthChecker;
use Rocket\Core\Security\Roles;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class HealthCheckController extends AbstractController
{
    /** Runs the network checks now (LDAP server, sending mailboxes) instead of waiting for the scheduler. */
    #[IsGranted(Roles::ADMIN)]
    #[Route('/api/health/check', name: 'api_health_check', methods: ['POST'])]
    public function __invoke(HealthChecker $checker, PlatformHealth $health): JsonResponse
    {
        $checker->checkAll(wait: true);

        return $this->json($health->check());
    }
}
