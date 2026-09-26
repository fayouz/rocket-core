<?php

namespace Rocket\Core\Controller;

use Lexik\Bundle\JWTAuthenticationBundle\Security\Authenticator\Token\JWTPostAuthenticationToken;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Rocket\Core\Entity\User;
use Rocket\Core\Event\UserLoggedOutEvent;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Signed-in user: the interface signs out (sessions are JWTs: forgetting the token is enough). Only announces it
 * (UserLoggedOutEvent) to the application, e.g. Rocket Auth ending the sign-ins of this session in the suite.
 */
final class LogoutController
{
    #[Route('/api/auth/logout', name: 'api_auth_logout', methods: ['POST'])]
    public function __invoke(Security $security, JWTTokenManagerInterface $jwt, EventDispatcherInterface $dispatcher): Response
    {
        $user = $security->getUser();
        $token = $security->getToken();
        // Sessions of users only: not applications, nor embedded pages.
        if ($user instanceof User && $token instanceof JWTPostAuthenticationToken) {
            $dispatcher->dispatch(new UserLoggedOutEvent($user, $jwt->decode($token) ?: []));
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
