<?php

namespace Rocket\Core\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Rocket\Core\Oidc\LogoutTokenValidator;
use Rocket\Core\Oidc\OidcException;
use Rocket\Core\Repository\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public: OpenID Connect Back-Channel Logout 1.0. The provider (Rocket Auth in suite mode) posts a signed logout token
 * when the user signs out there, or is disabled or deleted: every session of the linked account ends here.
 */
final class BackchannelLogoutController
{
    #[Route('/api/auth/oidc/backchannel-logout', name: 'api_auth_oidc_backchannel_logout', methods: ['POST'])]
    public function __invoke(Request $request, LogoutTokenValidator $validator, UserRepository $users, EntityManagerInterface $em, LoggerInterface $logger): JsonResponse
    {
        $token = $request->request->get('logout_token');
        if (!\is_string($token) || '' === $token) {
            return self::error('The logout_token parameter is required.');
        }

        try {
            [$server, $claims] = $validator->validate($token);
        } catch (OidcException $e) {
            $logger->warning('Back-channel logout refused: {message}', ['message' => $e->getMessage()]);

            return self::error($e->getMessage());
        }

        $user = $users->findOneBy(['authenticationServer' => $server, 'externalId' => $claims['sub']]);
        if (null !== $user) {
            $user->revokeSessions();
            $em->flush();
            $logger->info('Sessions of {email} revoked by {provider} (back-channel logout).', ['email' => $user->getEmail(), 'provider' => $server->getName()]);
        }

        // Unknown subject: nothing to end (the user never signed in here).
        return new JsonResponse(null, Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    private static function error(string $description): JsonResponse
    {
        return new JsonResponse(['error' => 'invalid_request', 'error_description' => $description], Response::HTTP_BAD_REQUEST, ['Cache-Control' => 'no-store']);
    }
}
