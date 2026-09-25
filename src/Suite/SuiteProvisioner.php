<?php

namespace Rocket\Core\Suite;

use Doctrine\ORM\EntityManagerInterface;
use Rocket\Core\Entity\AuthenticationServer;
use Rocket\Core\Enum\AuthenticationServerType;
use Rocket\Core\Repository\AuthenticationServerRepository;
use Rocket\Core\Security\SecretBox;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Keeps the Rocket Auth authentication server in line with the suite configuration (ROCKET_AUTH_*): created or
 * updated in suite mode, disabled and handed back to the administrators in standalone mode.
 * Run before the sign-in endpoints (SuiteRequestListener) and by `rocket:suite:sync`.
 */
final class SuiteProvisioner implements ResetInterface
{
    private bool $done = false;

    public function __construct(
        private readonly SuiteSettings $suite,
        private readonly AuthenticationServerRepository $servers,
        private readonly EntityManagerInterface $em,
        private readonly SecretBox $secrets,
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

        return $server;
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
