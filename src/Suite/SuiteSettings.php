<?php

namespace Rocket\Core\Suite;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Standalone or suite mode.
 *
 * - Standalone (default): the application manages its accounts (local, LDAP, OpenID Connect providers) itself.
 * - Suite (ROCKET_AUTH_URL set): Rocket Auth is the identity provider of the whole suite. Sign-in goes through it
 *   (declared automatically as a managed authentication server), administrators come from its group
 *   ROCKET_AUTH_ADMIN_GROUP, and users, groups and the directory are managed there. Local passwords only work
 *   with ROCKET_LOCAL_LOGIN=1 (emergency access, accounts created with app:user:create).
 */
final class SuiteSettings
{
    public const PROVIDER_NAME = 'Rocket Auth';

    public function __construct(
        #[Autowire(env: 'ROCKET_AUTH_URL')] private readonly string $authUrl,
        #[Autowire(env: 'ROCKET_AUTH_INTERNAL_URL')] private readonly string $authInternalUrl,
        #[Autowire(env: 'ROCKET_AUTH_CLIENT_ID')] private readonly string $clientId,
        #[Autowire(env: 'ROCKET_AUTH_CLIENT_SECRET')] #[\SensitiveParameter] private readonly string $clientSecret,
        #[Autowire(env: 'ROCKET_AUTH_ADMIN_GROUP')] private readonly string $adminGroup,
        #[Autowire(env: 'bool:ROCKET_LOCAL_LOGIN')] private readonly bool $localLogin,
        #[Autowire(param: 'rocket_core.app_id')] private readonly string $appId,
        #[Autowire(param: 'rocket_core.app_name')] private readonly string $appName,
    ) {
    }

    public function isSuite(): bool
    {
        return '' !== trim($this->authUrl);
    }

    /** Public URL of Rocket Auth (its issuer), as the browser sees it. */
    public function authUrl(): string
    {
        return rtrim(trim($this->authUrl), '/');
    }

    /** URL of Rocket Auth seen from this server (e.g. http://auth-api inside Docker); empty: the public one. */
    public function authInternalUrl(): string
    {
        return rtrim(trim($this->authInternalUrl), '/');
    }

    public function clientId(): string
    {
        return '' !== $this->clientId ? $this->clientId : 'rocket-'.$this->appId;
    }

    public function clientSecret(): string
    {
        return $this->clientSecret;
    }

    public function adminGroup(): string
    {
        return $this->adminGroup;
    }

    /** Local passwords are accepted: always in standalone mode, only with ROCKET_LOCAL_LOGIN=1 in suite mode. */
    public function isLocalLoginAllowed(): bool
    {
        return !$this->isSuite() || $this->localLogin;
    }

    public function appId(): string
    {
        return $this->appId;
    }

    public function appName(): string
    {
        return $this->appName;
    }
}
