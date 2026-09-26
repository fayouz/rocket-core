<?php

namespace Rocket\Core\Suite;

use Rocket\Core\Oidc\OidcClient;
use Rocket\Core\Oidc\OidcException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Suite mode: access tokens to call another brick of the suite as this application. Obtained from Rocket Auth with the
 * client credentials grant (ROCKET_AUTH_CLIENT_ID / ROCKET_AUTH_CLIENT_SECRET, through ROCKET_AUTH_INTERNAL_URL) for
 * the audience of the called brick (its client ID, e.g. "rocket-mailer"), and cached until shortly before they expire.
 *
 *     $http->request('POST', $mailerUrl.'/api/emails', ['auth_bearer' => $tokens->tokenFor('mailer'), …]);
 *
 * The called brick accepts it when its administrator linked an application to this client ID.
 */
class ServiceTokenProvider
{
    /** Renewed this many seconds before expiry, so a token never expires on its way. */
    private const MARGIN = 30;

    public function __construct(
        private readonly SuiteSettings $suite,
        private readonly SuiteProvisioner $provisioner,
        private readonly OidcClient $oidc,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
    ) {
    }

    /** Whether tokens can be requested: suite mode, with a client secret. */
    public function isAvailable(): bool
    {
        return $this->suite->isSuite() && '' !== $this->suite->clientSecret();
    }

    /**
     * A token for the brick of this identifier in the suite ("mailer": audience "rocket-mailer").
     *
     * @throws OidcException when Rocket Auth refuses or cannot be reached
     */
    public function tokenFor(string $appId): string
    {
        return $this->tokenForClient('rocket-'.$appId);
    }

    /**
     * A token for the OAuth client ID of the called brick.
     *
     * @throws OidcException
     */
    public function tokenForClient(string $audience): string
    {
        if (!$this->isAvailable()) {
            throw new OidcException('Calls between applications need the suite mode (ROCKET_AUTH_URL, ROCKET_AUTH_CLIENT_SECRET).');
        }
        $key = 'rocket_service_token_'.hash('xxh128', $this->suite->authUrl().'|'.$this->suite->clientId().'|'.$this->suite->clientSecret().'|'.$audience);

        return $this->cache->get($key, function (ItemInterface $item) use ($audience): string {
            [$token, $expiresIn] = $this->request($audience);
            $item->expiresAfter(max(1, $expiresIn - self::MARGIN));

            return $token;
        });
    }

    /** Forgets the cached token (e.g. the called brick answered 401). */
    public function forget(string $audience): void
    {
        $this->cache->delete('rocket_service_token_'.hash('xxh128', $this->suite->authUrl().'|'.$this->suite->clientId().'|'.$this->suite->clientSecret().'|'.$audience));
    }

    /** @return array{0: string, 1: int} */
    private function request(string $audience): array
    {
        $server = $this->provisioner->sync() ?? throw new OidcException('Rocket Auth is not configured.');
        $endpoint = $this->oidc->internalize((string) $this->oidc->discover($server)['token_endpoint'], $server->getUrl(), $server->getInternalUrl());
        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'auth_basic' => [rawurlencode($this->suite->clientId()), rawurlencode($this->suite->clientSecret())],
                'body' => ['grant_type' => 'client_credentials', 'audience' => $audience],
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 10,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw new OidcException(\sprintf('Rocket Auth cannot be reached (%s).', $e->getMessage()), previous: $e);
        }
        if ($status >= 400 || !\is_string($data['access_token'] ?? null)) {
            $error = $data['error_description'] ?? $data['error'] ?? 'HTTP '.$status;
            throw new OidcException(\sprintf('Rocket Auth refused a token for "%s": %s.', $audience, \is_string($error) ? $error : 'HTTP '.$status));
        }

        return [$data['access_token'], \is_int($data['expires_in'] ?? null) ? $data['expires_in'] : 300];
    }
}
