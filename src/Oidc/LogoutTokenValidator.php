<?php

namespace Rocket\Core\Oidc;

use Rocket\Core\Entity\AuthenticationServer;
use Rocket\Core\Repository\AuthenticationServerRepository;
use Rocket\Core\Suite\SuiteSettings;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Validates a logout token (OpenID Connect Back-Channel Logout 1.0, §2.6): signed by the provider it names (iss), for
 * this client (aud), with the back-channel logout event, no nonce, recent (iat), and seen only once (jti).
 */
final class LogoutTokenValidator
{
    public const EVENT = 'http://schemas.openid.net/event/backchannel-logout';
    private const MAX_AGE = 300;
    private const LEEWAY = 60;

    public function __construct(
        private readonly AuthenticationServerRepository $servers,
        private readonly OidcClient $client,
        private readonly SuiteSettings $suite,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return array{0: AuthenticationServer, 1: array<string, mixed>} the provider and the claims
     *
     * @throws OidcException
     */
    public function validate(string $logoutToken): array
    {
        [$header, $claims] = Jwt::decode($logoutToken);
        if (isset($header['typ']) && !\in_array(strtolower((string) $header['typ']), ['logout+jwt', 'jwt'], true)) {
            throw new OidcException('Not a logout token (typ).');
        }
        $issuer = rtrim((string) ($claims['iss'] ?? ''), '/');
        $server = $this->server($issuer) ?? throw new OidcException('Unknown issuer.');

        $claims = $this->client->verifySignature($server, $logoutToken);
        $now = time();
        if (rtrim((string) ($claims['iss'] ?? ''), '/') !== rtrim($server->getUrl(), '/')) {
            throw new OidcException('The logout token comes from another issuer.');
        }
        if (!\in_array($server->getClientId(), (array) ($claims['aud'] ?? []), true)) {
            throw new OidcException('The logout token was issued for another client.');
        }
        if (!\is_array($claims['events'] ?? null) || !\array_key_exists(self::EVENT, $claims['events'])) {
            throw new OidcException('The logout token has no back-channel logout event.');
        }
        if (\array_key_exists('nonce', $claims)) {
            throw new OidcException('A logout token must not contain a nonce.');
        }
        if (!\is_int($claims['iat'] ?? null) || $claims['iat'] < $now - self::MAX_AGE || $claims['iat'] > $now + self::LEEWAY) {
            throw new OidcException('The logout token is too old or issued in the future (iat).');
        }
        if (isset($claims['exp']) && (!\is_int($claims['exp']) || $claims['exp'] < $now - self::LEEWAY)) {
            throw new OidcException('The logout token has expired.');
        }
        if (!\is_string($claims['sub'] ?? null) || '' === $claims['sub']) {
            // A "sid" alone is not enough here: sessions are not tracked per provider session.
            throw new OidcException('The logout token has no subject (sub).');
        }
        if (!\is_string($claims['jti'] ?? null) || '' === $claims['jti']) {
            throw new OidcException('The logout token has no identifier (jti).');
        }

        // Replay: each token is accepted once (remembered longer than it stays fresh).
        $replayed = true;
        $this->cache->get('oidc_logout_jti_'.hash('xxh128', $issuer.'|'.$claims['jti']), static function (ItemInterface $item) use (&$replayed): bool {
            $item->expiresAfter(self::MAX_AGE + 2 * self::LEEWAY);
            $replayed = false;

            return true;
        });
        if ($replayed) {
            throw new OidcException('This logout token was already used (jti).');
        }

        return [$server, $claims];
    }

    private function server(string $issuer): ?AuthenticationServer
    {
        foreach ($this->servers->findEnabledOidc() as $server) {
            // Suite mode: only Rocket Auth signs people in, hence out.
            if (rtrim($server->getUrl(), '/') === $issuer && (!$this->suite->isSuite() || $server->isManaged())) {
                return $server;
            }
        }

        return null;
    }
}
