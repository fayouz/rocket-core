<?php

namespace Rocket\Core\Controller;

use Rocket\Core\Entity\Application;
use Rocket\Core\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ApplicationTokenController extends AbstractController
{
    /** Rotates the secret: the previous one stops working immediately. */
    #[Route('/api/applications/{id}/regenerate-token', name: 'api_application_regenerate_token', methods: ['POST'])]
    #[IsGranted(Roles::ADMIN)]
    public function __invoke(Application $application, EntityManagerInterface $em): JsonResponse
    {
        $token = $application->rotateToken();
        $em->flush();

        return $this->json(['token' => $token, 'tokenHint' => $application->getTokenHint()]);
    }
}
