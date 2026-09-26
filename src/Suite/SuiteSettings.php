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
    /** Where Rocket Auth sends the logout tokens (OpenID Connect Back-Channel Logout), under the brick's address. */
    public const BACKCHANNEL_LOGOUT_PATH = '/api/auth/oidc/backchannel-logout';

    public function __construct(
        #[Autowire(env: 'ROCKET_AUTH_URL')] private readonly string $authUrl,
        #[Autowire(env: 'ROCKET_AUTH_INTERNAL_URL')] private readonly string $authInternalUrl,
        #[Autowire(env: 'ROCKET_AUTH_CLIENT_ID')] private readonly string $clientId,
        #[Autowire(env: 'ROCKET_AUTH_CLIENT_SECRET')] #[\SensitiveParameter] private readonly string $clientSecret,
        #[Autowire(env: 'ROCKET_AUTH_ADMIN_GROUP')] private readonly string $adminGroup,
        #[Autowire(env: 'bool:ROCKET_LOCAL_LOGIN')] private readonly bool $localLogin,
        #[Autowire(param: 'rocket_core.app_id')] private readonly string $appId,
        #[Autowire(param: 'rocket_core.app_name')] private readonly string $appName,
        #[Autowire(env: 'ROCKET_PUBLIC_URL')] private readonly string $publicUrl = '',
        #[Autowire(env: 'ROCKET_INTERNAL_URL')] private readonly string $internalUrl = '',
        // Most bricks already know the address of their interface (links in emails…), which proxies /api.
        #[Autowire('%env(default::FRONTEND_URL)%')] private readonly ?string $frontendUrl = null,
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

    /** Public address of this brick (its interface, which proxies /api): ROCKET_PUBLIC_URL, else FRONTEND_URL. */
    public function publicUrl(): string
    {
        $url = '' !== trim($this->publicUrl) ? $this->publicUrl : (string) $this->frontendUrl;

        return rtrim(trim($url), '/');
    }

    /** Address of this brick as Rocket Auth's server reaches it (e.g. http://cloud-api inside Docker); else the public one. */
    public function internalUrl(): string
    {
        return '' !== trim($this->internalUrl) ? rtrim(trim($this->internalUrl), '/') : $this->publicUrl();
    }

    /** The back-channel logout endpoint of this brick, as registered in Rocket Auth; null when the brick has no known address. */
    public function backchannelLogoutUri(): ?string
    {
        $base = $this->internalUrl();

        return preg_match('#^https?://#i', $base) ? $base.self::BACKCHANNEL_LOGOUT_PATH : null;
    }
}
