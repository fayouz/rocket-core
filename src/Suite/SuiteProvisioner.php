<?php

namespace Rocket\Core\Suite;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Rocket\Core\Entity\AuthenticationServer;
use Rocket\Core\Enum\AuthenticationServerType;
use Rocket\Core\Repository\AuthenticationServerRepository;
use Rocket\Core\Security\SecretBox;
use Rocket\Core\Settings\Settings;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps the Rocket Auth authentication server in line with the suite configuration (ROCKET_AUTH_*): created or
 * updated in suite mode, disabled and handed back to the administrators in standalone mode.
 * In suite mode, it also declares the brick's back-channel logout endpoint to Rocket Auth (POST /oauth/suite/register,
 * authenticated with the client secret), once per address.
 * Run before the sign-in endpoints (SuiteRequestListener) and by `rocket:suite:sync`.
 */
final class SuiteProvisioner implements ResetInterface
{
    /** Setting: what was last declared to Rocket Auth (issuer, client, back-channel logout URI). */
    public const REGISTRATION_SETTING = 'suite.registration';
    private const RETRY_AFTER = 300;

    private bool $done = false;

    public function __construct(
        private readonly SuiteSettings $suite,
        private readonly AuthenticationServerRepository $servers,
        private readonly EntityManagerInterface $em,
        private readonly SecretBox $secrets,
        private readonly Settings $settings,
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function managedServer(): ?AuthenticationServer
    {
        return $this->servers->findOneBy(['managed' => true]);
    }

    /** @return AuthenticationServer|null the Rocket Auth server in suite mode */
    public function sync(): ?AuthenticationServer
    {
        $server = $this->managedServer();
        if (!$this->suite->isSuite()) {
            if (null !== $server) {
                // Back to standalone: the administrators decide what to do with it.
                $server->setManaged(false)->setEnabled(false);
                $this->em->flush();
            }

            return null;
        }

        $server ??= (new AuthenticationServer())->setManaged(true)->setType(AuthenticationServerType::Oidc);
        $secret = $this->suite->clientSecret();
        $current = '' === $server->getEncryptedClientSecret() ? '' : $this->decrypt($server->getEncryptedClientSecret());
        $changed = null === $server->getCreatedAt()
            || SuiteSettings::PROVIDER_NAME !== $server->getName()
            || !$server->isEnabled()
            || $server->getUrl() !== $this->suite->authUrl()
            || $server->getInternalUrl() !== $this->suite->authInternalUrl()
            || $server->getClientId() !== $this->suite->clientId()
            || $current !== $secret
            || $server->getAdminGroupDn() !== $this->suite->adminGroup()
            || !$server->isLinkExistingAccounts();
        if (!$changed) {
            $this->register();

            return $server;
        }

        $server
            ->setName(SuiteSettings::PROVIDER_NAME)
            ->setEnabled(true)
            ->setUrl($this->suite->authUrl())
            ->setInternalUrl($this->suite->authInternalUrl())
            ->setClientId($this->suite->clientId())
            ->setEncryptedClientSecret('' === $secret ? '' : $this->secrets->encrypt($secret))
            ->setScopes('openid email profile groups')
            ->setAdminGroupDn($this->suite->adminGroup())
            // Rocket Auth is the suite's own provider: accounts already known here are the same people.
            ->setLinkExistingAccounts(true);
        if (null === $server->getCreatedAt()) {
            $this->em->persist($server);
        }
        $this->em->flush();
        $this->register();

        return $server;
    }

    /**
     * Declares the back-channel logout endpoint of this brick to Rocket Auth, when not done yet for this configuration
     * (or always with $force). Failures are logged and retried 5 minutes later: sign-in does not depend on it.
     *
     * @return string|null the declared URI, null when not declared (no address for the brick, Rocket Auth unreachable…)
     */
    public function register(bool $force = false): ?string
    {
        $uri = $this->suite->backchannelLogoutUri();
        if (!$this->suite->isSuite() || null === $uri || '' === $this->suite->clientSecret()) {
            return null;
        }
        $registration = ['issuer' => $this->suite->authUrl(), 'clientId' => $this->suite->clientId(), 'backchannelLogoutUri' => $uri];
        if (!$force && $registration === $this->settings->get(self::REGISTRATION_SETTING)) {
            return $uri;
        }
        $retryKey = 'rocket_suite_registration_failed_'.hash('xxh128', json_encode($registration, \JSON_THROW_ON_ERROR));
        if (!$force && $this->cache->getItem($retryKey)->isHit()) {
            return null;
        }

        $base = '' !== $this->suite->authInternalUrl() ? $this->suite->authInternalUrl() : $this->suite->authUrl();
        try {
            $response = $this->httpClient->request('POST', $base.'/oauth/suite/register', [
                'auth_basic' => [rawurlencode($this->suite->clientId()), rawurlencode($this->suite->clientSecret())],
                'body' => ['backchannel_logout_uri' => $uri],
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 5,
            ]);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
            if ($status >= 300) {
                throw new \RuntimeException(\is_string($data['error_description'] ?? null) ? $data['error_description'] : 'HTTP '.$status);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Rocket Auth: the back-channel logout endpoint {uri} cannot be declared ({message}).', ['uri' => $uri, 'message' => $e->getMessage()]);
            $this->cache->save($this->cache->getItem($retryKey)->set(true)->expiresAfter(self::RETRY_AFTER));

            return null;
        }

        $this->settings->set(self::REGISTRATION_SETTING, $registration);
        $this->em->flush();

        return $uri;
    }

    /** Once per request or message (the listener calls it on every sign-in endpoint). */
    public function syncOnce(): void
    {
        if (!$this->done) {
            $this->done = true;
            $this->sync();
        }
    }

    public function reset(): void
    {
        $this->done = false;
    }

    private function decrypt(string $encrypted): string
    {
        try {
            return $this->secrets->decrypt($encrypted);
        } catch (\RuntimeException) {
            return "\0"; // unreadable (key changed): replaced
        }
    }
}
