<?php

namespace Rocket\Core\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTAuthenticatedEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Exception\InvalidTokenException;
use Rocket\Core\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Refuses the session JWTs issued before the user's sessions were revoked (User::$sessionsRevokedAt, set by the
 * back-channel logout of the identity provider). Sessions opened afterwards are not affected.
 */
#[AsEventListener(event: Events::JWT_AUTHENTICATED)]
final class SessionRevocationListener
{
    public function __invoke(JWTAuthenticatedEvent $event): void
    {
        $user = $event->getToken()->getUser();
        $issuedAt = $event->getPayload()['iat'] ?? null;
        if ($user instanceof User && \is_numeric($issuedAt) && $user->isSessionRevoked((int) $issuedAt)) {
            throw new InvalidTokenException('Session revoked.');
        }
    }
}
