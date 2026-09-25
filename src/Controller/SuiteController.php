<?php

namespace Rocket\Core\Controller;

use Rocket\Core\Oidc\OidcClient;
use Rocket\Core\Oidc\OidcException;
use Rocket\Core\Suite\SuiteApps;
use Rocket\Core\Suite\SuiteProvisioner;
use Rocket\Core\Suite\SuiteSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Public: how this application signs people in (standalone or suite), for the login page and the menu. */
final class SuiteController extends AbstractController
{
    #[Route('/api/suite', name: 'api_suite', methods: ['GET'])]
    public function __invoke(SuiteSettings $suite, SuiteProvisioner $provisioner, SuiteApps $apps, OidcClient $oidc): JsonResponse
    {
        $app = ['id' => $suite->appId(), 'name' => $suite->appName()];
        if (!$suite->isSuite()) {
            return $this->json(['mode' => 'standalone', 'app' => $app, 'localLogin' => true, 'auth' => null, 'apps' => []]);
        }

        $server = $provisioner->managedServer();
        $logoutUrl = null;
        if (null !== $server) {
            try {
                $endSession = $oidc->discover($server)['end_session_endpoint'] ?? null;
                // RP-initiated logout; the front adds post_logout_redirect_uri.
                $logoutUrl = \is_string($endSession) ? $endSession.(str_contains($endSession, '?') ? '&' : '?').'client_id='.rawurlencode($server->getClientId()) : null;
            } catch (OidcException) {
            }
        }

        return $this->json([
            'mode' => 'suite',
            'app' => $app,
            // Emergency access with a local password (ROCKET_LOCAL_LOGIN=1).
            'localLogin' => $suite->isLocalLoginAllowed(),
            'auth' => [
                'name' => SuiteSettings::PROVIDER_NAME,
                'url' => $suite->authUrl(),
                // Where users manage their account ("Mon compte"): the interface of Rocket Auth.
                'accountUrl' => $apps->accountUrl() ?? $suite->authUrl(),
                // The authentication server to use on the login page (see /api/auth/providers).
                'providerId' => (string) $server?->getId(),
                'logoutUrl' => $logoutUrl,
            ],
            'apps' => $apps->all(),
        ]);
    }
}
