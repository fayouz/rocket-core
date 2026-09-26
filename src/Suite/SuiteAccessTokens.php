<?php

namespace Rocket\Core\Suite;

use Rocket\Core\Oidc\Jwt;
use Rocket\Core\Oidc\OidcClient;
use Rocket\Core\Oidc\OidcException;

/**
 * Suite mode: access tokens of Rocket Auth that another brick obtained for itself (client credentials grant) to call
 * this one. Accepted when signed by Rocket Auth (its JWKS), issued by it, for this brick (aud = its client ID,
 * e.g. "rocket-mailer"), not expired, and without user (sub = the calling client). The caller is then the
 * application linked to that client (Application::$oauthClientId), see ApplicationTokenAuthenticator.
 */
final class SuiteAccessTokens
{
    private const LEEWAY = 30;

    public function __construct(
        private readonly SuiteSettings $suite,
        private readonly SuiteProvisioner $provisioner,
        private readonly OidcClient $client,
    ) {
    }

    /** Cheap check, without verifying anything: a JWT whose issuer is Rocket Auth (suite mode only). */
    public function isCandidate(string $token): bool
    {
        if (!$this->suite->isSuite() || 2 !== substr_count($token, '.')) {
            return false;
        }
        try {
            [, $claims] = Jwt::decode($token);
        } catch (OidcException) {
            return false;
        }

        return \is_string($claims['iss'] ?? null) && rtrim($claims['iss'], '/') === $this->suite->authUrl();
    }

    /**
     * @return string the OAuth client ID of the calling brick
     *
     * @throws OidcException
     */
    public function verify(string $token): string
    {
        $server = $this->provisioner->managedServer();
        if (null === $server || !$server->isEnabled()) {
            $server = $this->provisioner->sync() ?? throw new OidcException('Rocket Auth is not configured.');
        }
        $claims = $this->client->verifySignature($server, $token);

        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== $this->suite->authUrl()) {
            throw new OidcException('The access token comes from another issuer.');
        }
        if (!\in_array($this->suite->clientId(), (array) ($claims['aud'] ?? []), true)) {
            throw new OidcException(\sprintf('The access token is not meant for this application (audience "%s" expected).', $this->suite->clientId()));
        }
        if (!\is_int($claims['exp'] ?? null) || $claims['exp'] < time() - self::LEEWAY) {
            throw new OidcException('The access token has expired.');
        }
        if ('access' !== ($claims['token_use'] ?? 'access')) {
            throw new OidcException('Not an access token.');
        }
        $caller = $claims['azp'] ?? $claims['client_id'] ?? null;
        // Client credentials only: a user's access token is never accepted as an application.
        if (!\is_string($caller) || '' === $caller || ($claims['sub'] ?? null) !== $caller) {
            throw new OidcException('Only access tokens of an application (client credentials) are accepted.');
        }

        return $caller;
    }
}
