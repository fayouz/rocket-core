<?php

namespace Rocket\Core\Event;

use Rocket\Core\Entity\User;

/**
 * The user signed out of this application (POST /api/auth/logout, called by the interface before it forgets the session).
 * The core does nothing more: other sessions of the user stay open. Rocket Auth listens to it to end the sign-ins of
 * this session in the applications of the suite (refresh tokens, back-channel logout).
 */
final class UserLoggedOutEvent
{
    /** @param array<string, mixed> $session claims of the session JWT (iat, sid when the application adds one…) */
    public function __construct(
        public readonly User $user,
        public readonly array $session,
    ) {
    }
}
